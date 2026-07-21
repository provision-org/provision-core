<?php

namespace App\Services;

use App\Contracts\CommandExecutor;
use App\Models\Agent;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class OpenClawChatService
{
    private const HISTORY_LIMIT = 200;

    private const HISTORY_MAX_CHARS = 500_000;

    private const GATEWAY_REQUEST_TIMEOUT_MS = 15_000;

    private const NATIVE_ATTACHMENT_MAX_BYTES = 524_288;

    public function __construct(private readonly HarnessManager $harnessManager) {}

    /**
     * Send one durable, idempotent message through OpenClaw's native Gateway
     * protocol and wait for the canonical transcript to contain its reply.
     *
     * @param  null|callable(): bool  $cancelled
     * @return array{run_id: string, upstream_id: string, content: list<array<string, mixed>>}
     */
    public function sendAndWait(
        ChatConversation $conversation,
        ChatMessage $message,
        ?callable $cancelled = null,
        int $timeoutSeconds = 220,
        int $pollIntervalMilliseconds = 1_000,
    ): array {
        $conversation->loadMissing('agent.server');
        $agent = $conversation->agent;

        if (! $agent?->server || ! is_string($agent->harness_agent_id) || $agent->harness_agent_id === '') {
            throw new RuntimeException('The agent Gateway is not available.');
        }

        $executor = $this->harnessManager->resolveExecutor($agent->server);
        $sessionKey = $this->ensureNativeSessionKey($conversation, $agent);
        $idempotencyKey = "provision-chat:{$message->id}";
        $hasAttachments = collect($message->content)
            ->contains(fn (array $block) => ($block['type'] ?? null) !== 'text');
        $remoteDirectory = "/root/.openclaw/agents/{$agent->harness_agent_id}/provision-chat-attachments/{$message->id}";

        try {
            if ($cancelled !== null && $cancelled() === true) {
                throw new RuntimeException('The response was stopped.');
            }

            $prepared = $this->prepareMessage($executor, $agent, $message, $remoteDirectory);

            if ($cancelled !== null && $cancelled() === true) {
                throw new RuntimeException('The response was stopped.');
            }

            $sendResult = $this->callGateway($executor, 'chat.send', [
                'sessionKey' => $sessionKey,
                'agentId' => $agent->harness_agent_id,
                'message' => $prepared['message'],
                ...($prepared['attachments'] !== [] ? ['attachments' => $prepared['attachments']] : []),
                'idempotencyKey' => $idempotencyKey,
            ]);

            $runId = $sendResult['runId'] ?? null;
            if (! is_string($runId) || $runId === '') {
                throw new RuntimeException('The agent Gateway did not accept the message.');
            }

            if ($cancelled !== null && $cancelled() === true) {
                $this->abortWithExecutor($executor, $sessionKey, $agent->harness_agent_id, $runId);

                throw new RuntimeException('The response was stopped.');
            }

            $claimed = ChatMessage::query()
                ->whereKey($message->getKey())
                ->whereIn('delivery_status', ['queued', 'running'])
                ->update([
                    'delivery_status' => 'running',
                    'upstream_run_id' => $runId,
                    'delivery_error' => null,
                ]);

            if ($claimed === 0) {
                $message->refresh();
                if ($message->delivery_status === 'aborted') {
                    $this->abortWithExecutor($executor, $sessionKey, $agent->harness_agent_id, $runId);
                }

                throw new RuntimeException('The chat request is no longer active.');
            }

            $message->refresh();

            $deadline = microtime(true) + max(1, $timeoutSeconds);

            do {
                if ($cancelled !== null && $cancelled() === true) {
                    $this->abortWithExecutor($executor, $sessionKey, $agent->harness_agent_id, $runId);

                    throw new RuntimeException('The response was stopped.');
                }

                $history = $this->callGateway($executor, 'chat.history', [
                    'sessionKey' => $sessionKey,
                    'agentId' => $agent->harness_agent_id,
                    'limit' => self::HISTORY_LIMIT,
                    'maxChars' => self::HISTORY_MAX_CHARS,
                ]);

                $reply = $this->replyForIdempotencyKey($history, $idempotencyKey, $conversation);
                if ($reply !== null) {
                    return [
                        'run_id' => $runId,
                        'upstream_id' => $reply['upstream_id'],
                        'content' => $reply['content'],
                    ];
                }

                $sessionInfo = is_array($history['sessionInfo'] ?? null)
                    ? $history['sessionInfo']
                    : [];

                if (($sessionInfo['abortedLastRun'] ?? false) === true) {
                    throw new RuntimeException('The response was stopped.');
                }

                if (microtime(true) >= $deadline) {
                    break;
                }

                if ($pollIntervalMilliseconds > 0) {
                    usleep($pollIntervalMilliseconds * 1_000);
                }
            } while (true);

            $this->abortWithExecutor($executor, $sessionKey, $agent->harness_agent_id, $runId);

            throw new RuntimeException('The agent did not respond in time.');
        } finally {
            if ($hasAttachments) {
                $this->removeStagedAttachments($executor, $remoteDirectory, $conversation);
            }
        }
    }

    public function abort(ChatConversation $conversation, ?string $runId = null): void
    {
        $conversation->loadMissing('agent.server');
        $agent = $conversation->agent;

        if (! $agent?->server || ! is_string($agent->harness_agent_id) || $agent->harness_agent_id === '') {
            throw new RuntimeException('The agent Gateway is not available.');
        }

        $executor = $this->harnessManager->resolveExecutor($agent->server);
        $sessionKey = $this->ensureNativeSessionKey($conversation, $agent);
        $this->abortWithExecutor($executor, $sessionKey, $agent->harness_agent_id, $runId);
    }

    private function ensureNativeSessionKey(ChatConversation $conversation, Agent $agent): string
    {
        if (Str::startsWith($conversation->session_key, "agent:{$agent->harness_agent_id}:")) {
            return $conversation->session_key;
        }

        $sessionKey = "agent:{$agent->harness_agent_id}:dashboard:{$conversation->id}";
        $conversation->forceFill(['session_key' => $sessionKey])->save();

        return $sessionKey;
    }

    /**
     * @return array{message: string, attachments: list<array{type: string, mimeType: string, fileName: string, content: string}>}
     */
    private function prepareMessage(
        CommandExecutor $executor,
        Agent $agent,
        ChatMessage $message,
        string $remoteDirectory,
    ): array {
        $text = trim($message->textContent());
        $attachmentLines = [];
        $nativeAttachments = [];
        $nativeAttachmentBytes = 0;
        $attachmentIndex = 0;

        foreach ($message->content as $block) {
            if (($block['type'] ?? null) === 'text') {
                continue;
            }

            $path = $block['path'] ?? null;
            if (! is_string($path) || $path === '') {
                throw new RuntimeException('One of the attached files is no longer available.');
            }

            $disk = is_string($block['disk'] ?? null) ? $block['disk'] : null;
            $storage = $disk ? Storage::disk($disk) : Storage::disk(config('filesystems.default'));
            if (! $storage->exists($path)) {
                throw new RuntimeException('One of the attached files is no longer available.');
            }

            $contents = $storage->get($path);
            $originalName = basename((string) ($block['fileName'] ?? $path));
            $remoteName = $this->safeRemoteFilename(++$attachmentIndex, $originalName);
            $remotePath = "{$remoteDirectory}/{$remoteName}";

            $executor->exec('install -d -m 0700 '.escapeshellarg($remoteDirectory));
            $executor->writeFile($remotePath, $contents);
            $executor->exec('chmod 0600 '.escapeshellarg($remotePath));

            $mimeType = is_string($block['mimeType'] ?? null)
                ? $block['mimeType']
                : 'application/octet-stream';
            $attachmentLines[] = "- {$originalName} ({$mimeType}): {$remotePath}";

            $nextNativeBytes = $nativeAttachmentBytes + strlen($contents);
            if ($nextNativeBytes <= self::NATIVE_ATTACHMENT_MAX_BYTES) {
                $nativeAttachments[] = [
                    'type' => str_starts_with($mimeType, 'image/') ? 'image' : 'file',
                    'mimeType' => $mimeType,
                    'fileName' => $originalName,
                    'content' => base64_encode($contents),
                ];
                $nativeAttachmentBytes = $nextNativeBytes;
            }
        }

        if ($attachmentLines !== []) {
            $text .= ($text === '' ? '' : "\n\n")
                ."The user attached the following files. Inspect them as part of this request:\n"
                .implode("\n", $attachmentLines);
        }

        if ($text === '') {
            throw new RuntimeException('The message is empty.');
        }

        return [
            'message' => $text,
            'attachments' => $nativeAttachments,
        ];
    }

    private function removeStagedAttachments(
        CommandExecutor $executor,
        string $remoteDirectory,
        ChatConversation $conversation,
    ): void {
        try {
            $executor->exec('rm -rf -- '.escapeshellarg($remoteDirectory));
        } catch (Throwable $e) {
            Log::warning('Could not remove temporary OpenClaw chat attachments', [
                'conversation_id' => $conversation->id,
                'directory' => $remoteDirectory,
                'exception' => $e::class,
            ]);
        }
    }

    private function safeRemoteFilename(int $index, string $originalName): string
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $extension = preg_replace('/[^a-z0-9]/', '', $extension) ?: '';
        $base = pathinfo($originalName, PATHINFO_FILENAME);
        $base = Str::slug(Str::ascii($base));
        $base = $base !== '' ? Str::limit($base, 80, '') : 'attachment';

        return sprintf('%02d-%s%s', $index, $base, $extension !== '' ? ".{$extension}" : '');
    }

    /**
     * @param  array<string, mixed>  $history
     * @return array{upstream_id: string, content: list<array<string, mixed>>}|null
     */
    private function replyForIdempotencyKey(
        array $history,
        string $idempotencyKey,
        ChatConversation $conversation,
    ): ?array {
        $messages = is_array($history['messages'] ?? null) ? array_values($history['messages']) : [];
        $userIndex = null;

        foreach ($messages as $index => $candidate) {
            if (! is_array($candidate) || ($candidate['role'] ?? null) !== 'user') {
                continue;
            }

            $metadata = is_array($candidate['__openclaw'] ?? null) ? $candidate['__openclaw'] : [];
            $candidateKey = $candidate['idempotencyKey'] ?? $metadata['idempotencyKey'] ?? null;
            if ($candidateKey === "{$idempotencyKey}:user" || $candidateKey === $idempotencyKey) {
                $userIndex = $index;
            }
        }

        if ($userIndex === null) {
            return null;
        }

        foreach (array_slice($messages, $userIndex + 1) as $candidate) {
            if (! is_array($candidate) || ($candidate['role'] ?? null) !== 'assistant') {
                continue;
            }

            $content = $this->normalizeAssistantContent(
                $candidate['content'] ?? null,
                $conversation,
            );
            if ($content === []) {
                continue;
            }

            $metadata = is_array($candidate['__openclaw'] ?? null) ? $candidate['__openclaw'] : [];
            $upstreamId = $metadata['id'] ?? $candidate['responseId'] ?? null;
            if (! is_string($upstreamId) || $upstreamId === '') {
                $upstreamId = hash('sha256', json_encode($candidate, JSON_THROW_ON_ERROR));
            }

            return [
                'upstream_id' => "openclaw:{$upstreamId}",
                'content' => $content,
            ];
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizeAssistantContent(
        mixed $content,
        ChatConversation $conversation,
    ): array {
        $blocks = [];

        if (is_string($content) && trim($content) !== '') {
            $blocks[] = ['type' => 'text', 'text' => $content];
        } elseif (is_array($content)) {
            foreach ($content as $block) {
                if (is_array($block) && ($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                    $blocks[] = ['type' => 'text', 'text' => $block['text']];

                    continue;
                }

                if (is_array($block)) {
                    $media = $this->normalizeAssistantMedia($block, $conversation);
                    if ($media !== null) {
                        $blocks[] = $media;
                    }
                }
            }
        }

        foreach ($blocks as &$block) {
            if (($block['type'] ?? null) !== 'text') {
                continue;
            }

            $text = trim((string) ($block['text'] ?? ''));
            $block['text'] = preg_replace('/^\[\[reply_to_\w+\]\]\s*/', '', $text) ?? $text;
        }
        unset($block);

        return collect($blocks)
            ->filter(fn (array $block) => ($block['type'] ?? null) !== 'text'
                || trim((string) ($block['text'] ?? '')) !== '')
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>|null
     */
    private function normalizeAssistantMedia(array $block, ChatConversation $conversation): ?array
    {
        $type = (string) ($block['type'] ?? '');
        if (! in_array($type, ['image', 'image_url', 'file', 'audio'], true)) {
            return null;
        }

        $source = is_array($block['source'] ?? null) ? $block['source'] : [];
        $mimeType = (string) ($block['mimeType'] ?? $block['mime_type'] ?? $source['media_type'] ?? 'application/octet-stream');
        $fileName = basename((string) ($block['fileName'] ?? $block['filename'] ?? 'agent-output'));
        $urlValue = $block['url'] ?? null;
        if (is_array($block['image_url'] ?? null)) {
            $urlValue = $block['image_url']['url'] ?? $urlValue;
        } elseif (is_string($block['image_url'] ?? null)) {
            $urlValue = $block['image_url'];
        }

        $base64 = $block['content'] ?? $block['data'] ?? $source['data'] ?? null;
        if (is_string($urlValue) && str_starts_with($urlValue, 'data:')) {
            if (preg_match('/^data:([^;,]+);base64,(.+)$/s', $urlValue, $matches) === 1) {
                $mimeType = $matches[1];
                $base64 = $matches[2];
                $urlValue = null;
            }
        }

        if (is_string($base64) && $base64 !== '') {
            $decoded = base64_decode($base64, true);
            if ($decoded === false) {
                return null;
            }

            $extension = $this->extensionForMimeType($mimeType);
            $path = 'chat-agent-media/'.$conversation->id.'/'.strtolower((string) Str::ulid()).$extension;
            $disk = (string) config('filesystems.default', 'local');

            if (! Storage::disk($disk)->put($path, $decoded)) {
                return null;
            }

            return [
                'type' => str_starts_with($mimeType, 'image/') ? 'image' : 'file',
                'disk' => $disk,
                'path' => $path,
                'fileName' => $fileName !== 'agent-output' ? $fileName : basename($path),
                'mimeType' => $mimeType,
            ];
        }

        if (is_string($urlValue) && preg_match('/^https?:\/\//i', $urlValue) === 1) {
            return [
                'type' => str_starts_with($mimeType, 'image/') ? 'image' : 'file',
                'url' => $urlValue,
                'fileName' => $fileName,
                'mimeType' => $mimeType,
            ];
        }

        return null;
    }

    private function extensionForMimeType(string $mimeType): string
    {
        return match (strtolower($mimeType)) {
            'image/jpeg' => '.jpg',
            'image/png' => '.png',
            'image/gif' => '.gif',
            'image/webp' => '.webp',
            'application/pdf' => '.pdf',
            'audio/mpeg' => '.mp3',
            'audio/wav', 'audio/x-wav' => '.wav',
            default => '',
        };
    }

    private function abortWithExecutor(
        CommandExecutor $executor,
        string $sessionKey,
        string $agentId,
        ?string $runId,
    ): void {
        $this->callGateway($executor, 'chat.abort', [
            'sessionKey' => $sessionKey,
            'agentId' => $agentId,
            ...($runId ? ['runId' => $runId] : []),
        ]);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function callGateway(CommandExecutor $executor, string $method, array $params): array
    {
        $payload = json_encode($params, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $command = 'openclaw gateway call '.escapeshellarg($method)
            .' --json --params '.escapeshellarg($payload)
            .' --timeout '.self::GATEWAY_REQUEST_TIMEOUT_MS.' 2>&1';

        try {
            $output = trim($executor->exec($command));
        } catch (Throwable) {
            throw new RuntimeException('The agent Gateway could not be reached.');
        }

        if ($output === '') {
            throw new RuntimeException('The agent Gateway returned an empty response.');
        }

        try {
            $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new RuntimeException('The agent Gateway returned an invalid response.');
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('The agent Gateway returned an invalid response.');
        }

        if (($decoded['ok'] ?? true) === false || array_key_exists('error', $decoded)) {
            throw new RuntimeException('The agent Gateway rejected the request.');
        }

        return $decoded;
    }
}
