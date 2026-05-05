<?php

namespace App\Http\Controllers\Api;

use App\Enums\ChatMessageRole;
use App\Events\ChatAgentActivityEvent;
use App\Events\ChatMessageReceivedEvent;
use App\Events\ChatMessageStreamingEvent;
use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\AgentWebConnection;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WebChannelController extends Controller
{
    private const SIGNATURE_TOLERANCE_SECONDS = 300;

    private const STREAM_POLL_MS = 500;

    private const STREAM_MAX_DURATION_SECONDS = 25;

    private const UPLOAD_MAX_BYTES = 25 * 1024 * 1024;

    private const UPLOAD_URL_TTL_DAYS = 7;

    private const UPLOAD_ALLOWED_MIMES = [
        'image/png',
        'image/jpeg',
        'image/jpg',
        'image/gif',
        'image/webp',
        'application/pdf',
        'text/plain',
    ];

    /**
     * Plugin → Provision: agent has produced a message.
     *
     * Body shape: { accountId, conversationId, kind: 'text'|'media', text?, mediaUrl?, mediaMime?, replyToId? }
     * Header: X-Provision-Signature: t=<unix>,v1=<hmac-sha256(secret, "<unix>.<body>")>
     */
    public function inbound(Request $request): JsonResponse
    {
        $body = $request->getContent();
        $payload = $request->json()->all();
        $accountId = $payload['accountId'] ?? null;

        if (! is_string($accountId) || $accountId === '') {
            return response()->json(['error' => 'missing_accountId'], 422);
        }

        $connection = AgentWebConnection::query()
            ->where('account_id', $accountId)
            ->first();

        if (! $connection) {
            return response()->json(['error' => 'unknown_account'], 404);
        }

        $signatureHeader = $request->header('X-Provision-Signature');
        if (! $this->verifySignature($body, $signatureHeader, $connection->webhook_secret)) {
            Log::warning('provision-web inbound: signature failure', ['accountId' => $accountId]);

            return response()->json(['error' => 'invalid_signature'], 403);
        }

        $agent = $connection->agent;
        if (! $agent) {
            return response()->json(['error' => 'orphaned_account'], 404);
        }

        $conversationId = $payload['conversationId'] ?? null;
        $conversation = $conversationId
            ? ChatConversation::query()
                ->where('id', $conversationId)
                ->where('agent_id', $agent->id)
                ->first()
            : null;

        if (! $conversation) {
            $conversation = ChatConversation::create([
                'agent_id' => $agent->id,
                'user_id' => $agent->team?->user_id,
                'session_key' => 'web:'.Str::ulid()->toBase32(),
                'last_message_at' => now(),
            ]);
        }

        $content = $this->buildContentBlocks($payload);
        if (empty($content)) {
            return response()->json(['error' => 'empty_payload'], 422);
        }

        $message = ChatMessage::create([
            'chat_conversation_id' => $conversation->id,
            'role' => ChatMessageRole::Assistant,
            'content' => $content,
            'sent_at' => now(),
        ]);

        $conversation->update(['last_message_at' => now()]);

        try {
            broadcast(new ChatMessageReceivedEvent($message, $agent->team_id));
        } catch (\Throwable $e) {
            Log::warning('provision-web inbound: broadcast failed', [
                'agent_id' => $agent->id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'messageId' => $message->id,
            'conversationId' => $conversation->id,
        ]);
    }

    /**
     * Plugin → Provision: tool/work activity update for the typing indicator.
     *
     * Body shape: { accountId, conversationId, kind: 'tool'|'idle', tool?, label?, phase? }
     */
    public function activity(Request $request): JsonResponse
    {
        [$resolved, $error] = $this->resolveSignedRequest($request);
        if ($error !== null) {
            return $error;
        }
        [$agent, $conversation, $payload] = $resolved;

        $kind = $payload['kind'] ?? 'tool';
        $broadcastPayload = [
            'kind' => is_string($kind) ? $kind : 'tool',
            'tool' => isset($payload['tool']) && is_string($payload['tool']) ? $payload['tool'] : null,
            'label' => isset($payload['label']) && is_string($payload['label']) ? $payload['label'] : null,
            'phase' => isset($payload['phase']) && is_string($payload['phase']) ? $payload['phase'] : null,
        ];

        try {
            broadcast(new ChatAgentActivityEvent($conversation->id, $broadcastPayload, $agent->team_id));
        } catch (\Throwable $e) {
            Log::warning('provision-web activity: broadcast failed', [
                'agent_id' => $agent->id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Plugin → Provision: incremental text delta for in-progress assistant reply.
     *
     * Body shape: { accountId, conversationId, streamId, delta, cumulative, isFinal? }
     */
    public function streamChunk(Request $request): JsonResponse
    {
        [$resolved, $error] = $this->resolveSignedRequest($request);
        if ($error !== null) {
            return $error;
        }
        [$agent, $conversation, $payload] = $resolved;

        $streamId = $payload['streamId'] ?? null;
        $delta = $payload['delta'] ?? '';
        $cumulative = $payload['cumulative'] ?? '';
        $isFinal = (bool) ($payload['isFinal'] ?? false);

        if (! is_string($streamId) || $streamId === '') {
            return response()->json(['error' => 'missing_streamId'], 422);
        }
        if (! is_string($delta) || ! is_string($cumulative)) {
            return response()->json(['error' => 'invalid_chunk'], 422);
        }

        try {
            broadcast(new ChatMessageStreamingEvent(
                conversationId: $conversation->id,
                streamId: $streamId,
                delta: $delta,
                cumulative: $cumulative,
                isFinal: $isFinal,
                teamId: $agent->team_id,
            ));
        } catch (\Throwable $e) {
            Log::warning('provision-web stream-chunk: broadcast failed', [
                'agent_id' => $agent->id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Shared HMAC + connection lookup for plugin → Provision endpoints. Returns
     * either a tuple of [agent, conversation, payload] or a 4xx JsonResponse to
     * short-circuit the caller.
     *
     * @return array{0: ?array{0: Agent, 1: ChatConversation, 2: array<string, mixed>}, 1: ?JsonResponse}
     */
    private function resolveSignedRequest(Request $request): array
    {
        $body = $request->getContent();
        $payload = $request->json()->all();
        $accountId = $payload['accountId'] ?? null;

        if (! is_string($accountId) || $accountId === '') {
            return [null, response()->json(['error' => 'missing_accountId'], 422)];
        }

        $connection = AgentWebConnection::query()
            ->where('account_id', $accountId)
            ->first();

        if (! $connection) {
            return [null, response()->json(['error' => 'unknown_account'], 404)];
        }

        $signatureHeader = $request->header('X-Provision-Signature');
        if (! $this->verifySignature($body, $signatureHeader, $connection->webhook_secret)) {
            return [null, response()->json(['error' => 'invalid_signature'], 403)];
        }

        $agent = $connection->agent;
        if (! $agent) {
            return [null, response()->json(['error' => 'orphaned_account'], 404)];
        }

        $conversationId = $payload['conversationId'] ?? null;
        if (! is_string($conversationId) || $conversationId === '') {
            return [null, response()->json(['error' => 'missing_conversationId'], 422)];
        }

        $conversation = ChatConversation::query()
            ->where('id', $conversationId)
            ->where('agent_id', $agent->id)
            ->first();

        if (! $conversation) {
            return [null, response()->json(['error' => 'unknown_conversation'], 404)];
        }

        return [[$agent, $conversation, $payload], null];
    }

    /**
     * Plugin opens this SSE stream and consumes events. The plugin must auth
     * with `Authorization: Bearer <api_token>` matching the connection.
     *
     * Streams events of shape:
     *   event: message
     *   data: { "messageId": "...", "conversationId": "...", "kind": "text", "text": "...", "userId": "..." }
     *
     * Holds the connection for STREAM_MAX_DURATION_SECONDS then cleanly closes;
     * the plugin is expected to reconnect.
     */
    public function stream(Request $request, string $accountId): StreamedResponse
    {
        $connection = AgentWebConnection::query()
            ->where('account_id', $accountId)
            ->first();

        abort_unless($connection, 404);

        $bearer = $request->bearerToken();
        abort_unless(
            $bearer && hash_equals((string) $connection->api_token, $bearer),
            401,
        );

        return response()->stream(function () use ($connection) {
            $deadline = microtime(true) + self::STREAM_MAX_DURATION_SECONDS;
            $agent = $connection->agent;
            if (! $agent) {
                return;
            }

            echo "retry: 2000\n\n";
            $this->flush();

            while (microtime(true) < $deadline) {
                if (connection_aborted()) {
                    break;
                }

                $messages = ChatMessage::query()
                    ->whereHas('conversation', fn ($q) => $q->where('agent_id', $agent->id))
                    ->where('role', ChatMessageRole::User)
                    ->whereNull('outbound_to_agent_at')
                    ->orderBy('sent_at')
                    ->limit(20)
                    ->get();

                foreach ($messages as $message) {
                    $event = json_encode([
                        'messageId' => $message->id,
                        'conversationId' => $message->chat_conversation_id,
                        'kind' => 'text',
                        'text' => $message->textContent(),
                        'attachments' => $message->attachments(),
                    ]);

                    echo "event: message\n";
                    echo 'data: '.$event."\n\n";
                    $this->flush();

                    $message->update(['outbound_to_agent_at' => now()]);
                }

                if ($messages->isEmpty()) {
                    echo ": ping\n\n";
                    $this->flush();
                }

                usleep(self::STREAM_POLL_MS * 1000);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ]);
    }

    /**
     * Probe endpoint for plugin status checks.
     */
    public function probe(Request $request, string $accountId): JsonResponse
    {
        $connection = AgentWebConnection::query()
            ->where('account_id', $accountId)
            ->first();

        abort_unless($connection, 404);

        $bearer = $request->bearerToken();
        abort_unless(
            $bearer && hash_equals((string) $connection->api_token, $bearer),
            401,
        );

        return response()->json([
            'ok' => true,
            'accountId' => $connection->account_id,
            'agentId' => $connection->agent?->harness_agent_id,
        ]);
    }

    /**
     * Plugin uploads a file (e.g. a screenshot) and gets back a signed URL
     * pointing at R2. The plugin then references that URL in the inbound
     * webhook payload via `mediaUrl`. Auth is the same bearer api_token used
     * by /stream and /probe — that token is per-account and HMAC-equivalent.
     */
    public function upload(Request $request, string $accountId): JsonResponse
    {
        $connection = AgentWebConnection::query()
            ->where('account_id', $accountId)
            ->first();

        abort_unless($connection, 404);

        $bearer = $request->bearerToken();
        abort_unless(
            $bearer && hash_equals((string) $connection->api_token, $bearer),
            401,
        );

        $file = $request->file('file');
        if (! $file || ! $file->isValid()) {
            return response()->json(['error' => 'missing_file'], 422);
        }

        if ($file->getSize() > self::UPLOAD_MAX_BYTES) {
            return response()->json(['error' => 'file_too_large'], 413);
        }

        $mime = $file->getMimeType() ?? 'application/octet-stream';
        if (! in_array($mime, self::UPLOAD_ALLOWED_MIMES, true)) {
            return response()->json(['error' => 'unsupported_mime', 'mime' => $mime], 415);
        }

        $agent = $connection->agent;
        if (! $agent) {
            return response()->json(['error' => 'orphaned_account'], 404);
        }

        $ext = $file->guessExtension() ?: 'bin';
        $path = sprintf(
            'web-channel/%s/%s/%s.%s',
            $agent->team_id,
            $accountId,
            (string) Str::ulid(),
            $ext,
        );

        try {
            $disk = Storage::disk('r2');
            $stream = fopen($file->getRealPath(), 'r');
            $disk->writeStream($path, $stream, [
                'visibility' => 'private',
                'ContentType' => $mime,
            ]);
            if (is_resource($stream)) {
                fclose($stream);
            }

            $url = $disk->temporaryUrl(
                $path,
                now()->addDays(self::UPLOAD_URL_TTL_DAYS),
            );
        } catch (\Throwable $e) {
            Log::error('provision-web upload: r2 store failed', [
                'agent_id' => $agent->id,
                'account_id' => $accountId,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'storage_failure'], 502);
        }

        return response()->json([
            'url' => $url,
            'mime' => $mime,
            'size' => $file->getSize(),
            'path' => $path,
        ]);
    }

    /**
     * Verify a signature header of shape `t=<unix>,v1=<hex-hmac>`.
     */
    private function verifySignature(string $body, ?string $header, string $secret): bool
    {
        if (! $header) {
            return false;
        }

        $parts = [];
        foreach (explode(',', $header) as $piece) {
            [$k, $v] = array_pad(explode('=', trim($piece), 2), 2, null);
            if ($k && $v !== null) {
                $parts[$k] = $v;
            }
        }

        $ts = $parts['t'] ?? null;
        $sig = $parts['v1'] ?? null;
        if (! is_numeric($ts) || ! is_string($sig)) {
            return false;
        }

        $age = abs(time() - (int) $ts);
        if ($age > self::SIGNATURE_TOLERANCE_SECONDS) {
            return false;
        }

        $expected = hash_hmac('sha256', $ts.'.'.$body, $secret);

        return hash_equals($expected, $sig);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function buildContentBlocks(array $payload): array
    {
        $blocks = [];
        $kind = $payload['kind'] ?? 'text';
        $text = $payload['text'] ?? null;

        if ($kind === 'text' && is_string($text) && $text !== '') {
            $blocks[] = ['type' => 'text', 'text' => $text];
        }

        if ($kind === 'media' && is_string($payload['mediaUrl'] ?? null)) {
            if (is_string($text) && $text !== '') {
                $blocks[] = ['type' => 'text', 'text' => $text];
            }
            $blocks[] = [
                'type' => 'image',
                'url' => $payload['mediaUrl'],
                'mimeType' => $payload['mediaMime'] ?? 'image/png',
            ];
        }

        return $blocks;
    }

    private function flush(): void
    {
        if (function_exists('ob_flush')) {
            @ob_flush();
        }
        flush();
    }
}
