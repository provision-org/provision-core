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

    private const GATEWAY_MEDIA_MAX_BYTES = 10_485_760;

    private const GATEWAY_MEDIA_MAX_PATH_LENGTH = 4_096;

    /**
     * Keep the base64-expanded Gateway payload comfortably below Linux's
     * per-argument limit. Larger files remain available through their staged
     * workspace paths included in the message.
     */
    private const NATIVE_ATTACHMENT_MAX_BYTES = 49_152;

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

                $sessionInfo = is_array($history['sessionInfo'] ?? null)
                    ? $history['sessionInfo']
                    : [];

                if (! $this->historyHasActiveRun($sessionInfo, $runId)) {
                    $reply = $this->replyForIdempotencyKey(
                        $history,
                        $idempotencyKey,
                        $conversation,
                        $executor,
                    );
                    if ($reply !== null) {
                        return [
                            'run_id' => $runId,
                            'upstream_id' => $reply['upstream_id'],
                            'content' => $reply['content'],
                        ];
                    }
                }

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
        CommandExecutor $executor,
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

        $candidates = array_slice($messages, $userIndex + 1);

        for ($index = count($candidates) - 1; $index >= 0; $index--) {
            $candidate = $candidates[$index];

            if (! is_array($candidate) || ($candidate['role'] ?? null) !== 'assistant') {
                continue;
            }

            $content = $this->normalizeAssistantContent(
                $candidate['content'] ?? null,
                $conversation,
                $executor,
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
     * @param  array<string, mixed>  $sessionInfo
     */
    private function historyHasActiveRun(array $sessionInfo, string $runId): bool
    {
        if (($sessionInfo['hasActiveRun'] ?? false) === true) {
            return true;
        }

        $activeRunIds = is_array($sessionInfo['activeRunIds'] ?? null)
            ? $sessionInfo['activeRunIds']
            : [];

        if (in_array($runId, $activeRunIds, true)) {
            return true;
        }

        return in_array($sessionInfo['status'] ?? null, ['queued', 'started', 'running'], true);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizeAssistantContent(
        mixed $content,
        ChatConversation $conversation,
        CommandExecutor $executor,
    ): array {
        $blocks = [];

        if (is_string($content) && trim($content) !== '') {
            $blocks = $this->normalizeAssistantText($content, $conversation, $executor);
        } elseif (is_array($content)) {
            foreach ($content as $block) {
                if (is_array($block) && ($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                    array_push(
                        $blocks,
                        ...$this->normalizeAssistantText($block['text'], $conversation, $executor),
                    );

                    continue;
                }

                if (is_array($block)) {
                    $media = $this->normalizeAssistantMedia($block, $conversation, $executor);
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
     * @return list<array<string, mixed>>
     */
    private function normalizeAssistantText(
        string $text,
        ChatConversation $conversation,
        CommandExecutor $executor,
    ): array {
        $textLines = [];
        $mediaPaths = [];

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if (preg_match('/^\s*MEDIA:\s*(\S+)\s*$/', $line, $matches) === 1) {
                $mediaPaths[] = $matches[1];

                continue;
            }

            $textLines[] = $line;
        }

        $blocks = [];
        $visibleText = trim(implode("\n", $textLines));
        if ($visibleText !== '') {
            $blocks[] = ['type' => 'text', 'text' => $visibleText];
        }

        foreach ($mediaPaths as $mediaPath) {
            $contents = $this->fetchAgentMediaFile($executor, $conversation, $mediaPath);
            $fileName = basename($mediaPath);
            $mimeType = $this->mimeTypeForFileName($fileName);
            $media = $this->persistAssistantMedia($contents, $mimeType, $fileName, $conversation);

            $blocks[] = $media;
        }

        return $blocks;
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>|null
     */
    private function normalizeAssistantMedia(
        array $block,
        ChatConversation $conversation,
        CommandExecutor $executor,
    ): ?array {
        $type = (string) ($block['type'] ?? '');
        if (! in_array($type, ['image', 'image_url', 'file', 'audio'], true)) {
            return null;
        }

        $source = is_array($block['source'] ?? null) ? $block['source'] : [];
        $mimeType = (string) ($block['mimeType'] ?? $block['mime_type'] ?? $source['media_type'] ?? 'application/octet-stream');
        $fileName = basename((string) ($block['fileName'] ?? $block['filename'] ?? $block['alt'] ?? 'agent-output'));
        $fileName = $fileName !== '' ? Str::limit($fileName, 255, '') : 'agent-output';
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

            return $this->persistAssistantMedia($decoded, $mimeType, $fileName, $conversation);
        }

        if (is_string($urlValue) && str_starts_with($urlValue, '/api/chat/media/')) {
            $decoded = $this->fetchGatewayMedia($executor, $conversation, $urlValue);

            return $this->persistAssistantMedia($decoded, $mimeType, $fileName, $conversation);
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

    /**
     * Fetch a MEDIA directive only from this agent's isolated media directory.
     */
    private function fetchAgentMediaFile(
        CommandExecutor $executor,
        ChatConversation $conversation,
        string $path,
    ): string {
        $agentId = $conversation->agent?->harness_agent_id;
        if (! is_string($agentId) || $agentId === '' || preg_match('/^[a-zA-Z0-9_-]+$/', $agentId) !== 1) {
            throw new RuntimeException('The agent media could not be retrieved.');
        }

        $baseDirectory = "/root/.openclaw/media/{$agentId}";
        if (strlen($path) > self::GATEWAY_MEDIA_MAX_PATH_LENGTH
            || preg_match('/[\x00-\x1F\x7F]/', $path) === 1
            || ! str_starts_with($path, "{$baseDirectory}/")) {
            throw new RuntimeException('The agent media could not be retrieved.');
        }

        $maxBytes = self::GATEWAY_MEDIA_MAX_BYTES;
        $script = <<<'JS'
const fs = require('fs');
const mediaPath = process.argv[1];
const baseDirectory = process.argv[2];
const maxBytes = Number(process.argv[3]);
const fail = () => {
  process.stderr.write('Agent media fetch failed.');
  process.exit(1);
};

try {
  const resolvedBase = fs.realpathSync(baseDirectory);
  const resolvedPath = fs.realpathSync(mediaPath);
  const stats = fs.statSync(resolvedPath);

  if (!resolvedPath.startsWith(`${resolvedBase}/`) || !stats.isFile() || stats.size === 0 || stats.size > maxBytes) {
    fail();
  } else {
    process.stdout.write(fs.readFileSync(resolvedPath).toString('base64'));
  }
} catch {
  fail();
}
JS;
        $command = 'node -e '.escapeshellarg($script)
            .' '.escapeshellarg($path)
            .' '.escapeshellarg($baseDirectory)
            .' '.escapeshellarg((string) $maxBytes);

        return $this->decodeRemoteMedia($executor, $command, $maxBytes);
    }

    /**
     * Fetch authenticated Gateway media without moving the Gateway token off
     * the agent server or exposing it in the remote process arguments.
     */
    private function fetchGatewayMedia(
        CommandExecutor $executor,
        ChatConversation $conversation,
        string $path,
    ): string {
        if (strlen($path) > self::GATEWAY_MEDIA_MAX_PATH_LENGTH
            || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            throw new RuntimeException('The agent media could not be retrieved.');
        }

        $matches = [];
        $routePattern = '~\A/api/chat/media/outgoing/([^/?#]+)/'
            .'([0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})/full\z~i';
        if (preg_match($routePattern, $path, $matches) !== 1) {
            throw new RuntimeException('The agent media could not be retrieved.');
        }

        $mediaSessionKey = rawurldecode($matches[1]);
        if (! hash_equals($conversation->session_key, $mediaSessionKey)) {
            throw new RuntimeException('The agent media could not be retrieved.');
        }

        $maxBytes = self::GATEWAY_MEDIA_MAX_BYTES;
        $script = <<<'JS'
const fs = require('fs');
const mediaPath = process.argv[1];
const maxBytes = Number(process.argv[2]);
const fail = () => {
  process.stderr.write('Gateway media fetch failed.');
  process.exit(1);
};

(async () => {
  try {
    const config = JSON.parse(fs.readFileSync('/root/.openclaw/openclaw.json', 'utf8'));
    const token = config?.gateway?.auth?.token;
    const port = Number(config?.gateway?.port ?? 18789);

    if (typeof token !== 'string' || token === '' || !Number.isInteger(port) || port < 1 || port > 65535) {
      fail();
      return;
    }

    const response = await fetch(`http://127.0.0.1:${port}${mediaPath}`, {
      headers: { Authorization: `Bearer ${token}` },
      redirect: 'error',
      signal: AbortSignal.timeout(15000),
    });
    const contentLength = Number(response.headers.get('content-length') ?? 0);

    if (!response.ok || (Number.isFinite(contentLength) && contentLength > maxBytes)) {
      fail();
      return;
    }

    const bytes = Buffer.from(await response.arrayBuffer());
    if (bytes.length === 0 || bytes.length > maxBytes) {
      fail();
      return;
    }

    process.stdout.write(bytes.toString('base64'));
  } catch {
    fail();
  }
})();
JS;
        $command = 'node -e '.escapeshellarg($script)
            .' '.escapeshellarg($path)
            .' '.escapeshellarg((string) $maxBytes);

        return $this->decodeRemoteMedia($executor, $command, $maxBytes);
    }

    private function decodeRemoteMedia(
        CommandExecutor $executor,
        string $command,
        int $maxBytes,
    ): string {
        try {
            $output = $executor->exec($command);
            $base64 = preg_replace('/\s+/', '', $output);
            $decoded = is_string($base64) ? base64_decode($base64, true) : false;
        } catch (Throwable) {
            throw new RuntimeException('The agent media could not be retrieved.');
        }

        if ($decoded === false || $decoded === '' || strlen($decoded) > $maxBytes) {
            throw new RuntimeException('The agent media could not be retrieved.');
        }

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function persistAssistantMedia(
        string $contents,
        string $mimeType,
        string $fileName,
        ChatConversation $conversation,
    ): array {
        $extension = $this->extensionForMimeType($mimeType);
        $path = 'chat-agent-media/'.$conversation->id.'/'.strtolower((string) Str::ulid()).$extension;
        $disk = (string) config('filesystems.default', 'local');

        if (! Storage::disk($disk)->put($path, $contents)) {
            throw new RuntimeException('The agent media could not be stored.');
        }

        return [
            'type' => str_starts_with($mimeType, 'image/') ? 'image' : 'file',
            'disk' => $disk,
            'path' => $path,
            'fileName' => $fileName !== 'agent-output' ? $fileName : basename($path),
            'mimeType' => $mimeType,
        ];
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

    private function mimeTypeForFileName(string $fileName): string
    {
        return match (strtolower(pathinfo($fileName, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'pdf' => 'application/pdf',
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            default => 'application/octet-stream',
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
