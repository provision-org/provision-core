<?php

namespace App\Http\Controllers\Api;

use App\Enums\ChatMessageRole;
use App\Events\ChatMessageReceivedEvent;
use App\Http\Controllers\Controller;
use App\Models\AgentWebConnection;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WebChannelController extends Controller
{
    private const SIGNATURE_TOLERANCE_SECONDS = 300;

    private const STREAM_POLL_MS = 500;

    private const STREAM_MAX_DURATION_SECONDS = 25;

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
