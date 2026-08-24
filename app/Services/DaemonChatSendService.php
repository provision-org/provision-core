<?php

namespace App\Services;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\Server;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Fast-path chat delivery through the on-server daemon.
 *
 * The SSH send path costs ~5-15s before the model sees the message (queue
 * pickup + SSH handshake + remote CLI startup). provisiond already holds an
 * open loopback WebSocket to the gateway, so instead we drop the send into a
 * Redis outbox the daemon long-polls, and it fires `chat.send` within ~100ms.
 * The daemon acks through the outbox API; if no ack arrives in time the
 * caller falls back to the SSH path — chat.send is idempotent by
 * idempotencyKey, so a late daemon send after fallback is harmless.
 *
 * Scope: text-only messages. Attachments need staging on the box, which is
 * the SSH path's job.
 */
class DaemonChatSendService
{
    private const CAPABILITY = 'chat-send-v1';

    private const ACK_TIMEOUT_SECONDS = 4;

    private const HEARTBEAT_FRESH_MINUTES = 2;

    /** Entries older than this are skipped by the daemon (stale after fallback). */
    public const OUTBOX_ENTRY_TTL_SECONDS = 30;

    public function __construct(
        private OpenClawChatService $chatService,
        private int $ackTimeoutSeconds = self::ACK_TIMEOUT_SECONDS,
    ) {}

    /**
     * Attempt fast delivery. Returns true when the daemon acked the send —
     * the message is running upstream and deltas will stream via the relay.
     * Returns false when ineligible or the daemon did not ack in time; the
     * caller must fall back to the SSH path.
     */
    public function attempt(ChatConversation $conversation, ChatMessage $message): bool
    {
        $conversation->loadMissing('agent.server');
        $agent = $conversation->agent;
        $server = $agent?->server;

        if (! $server || ! $this->eligible($server, $message)) {
            return false;
        }

        $sessionKey = $this->chatService->ensureNativeSessionKey($conversation, $agent);
        $idempotencyKey = "provision-chat:{$message->id}";

        // Same claim CAS as the SSH path: only one sender wins the message.
        $claimed = ChatMessage::query()
            ->whereKey($message->getKey())
            ->whereIn('delivery_status', ['queued', 'running'])
            ->update([
                'delivery_status' => 'running',
                'upstream_run_id' => $idempotencyKey,
                'delivery_error' => null,
            ]);

        if ($claimed === 0) {
            return false;
        }

        Redis::connection()->client()->rpush(self::outboxKey($server), json_encode([
            'message_id' => $message->id,
            'session_key' => $sessionKey,
            'agent_id' => $agent->harness_agent_id,
            'message' => trim($message->textContent()),
            'idempotency_key' => $idempotencyKey,
            'queued_at' => now()->getTimestampMs(),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $ack = Redis::connection()->client()->blpop([self::ackKey($message)], $this->ackTimeoutSeconds);
        $payload = is_array($ack) && isset($ack[1]) ? json_decode((string) $ack[1], true) : null;

        if (($payload['status'] ?? null) === 'started') {
            return true;
        }

        Log::info('Daemon fast-send did not ack; falling back to SSH path', [
            'message_id' => $message->id,
            'server_id' => $server->id,
            'ack_status' => $payload['status'] ?? 'timeout',
            'ack_error' => $payload['error'] ?? null,
        ]);

        // Reset the claim so the SSH path's own CAS can re-claim cleanly.
        ChatMessage::query()
            ->whereKey($message->getKey())
            ->where('delivery_status', 'running')
            ->update(['delivery_status' => 'queued']);

        return false;
    }

    private function eligible(Server $server, ChatMessage $message): bool
    {
        $heartbeatFresh = $server->last_health_check?->isAfter(
            now()->subMinutes(self::HEARTBEAT_FRESH_MINUTES),
        ) ?? false;

        $hasCapability = in_array(self::CAPABILITY, $server->daemon_capabilities ?? [], true);

        $textOnly = collect($message->content)
            ->every(fn (array $block) => ($block['type'] ?? null) === 'text');

        return $heartbeatFresh && $hasCapability && $textOnly;
    }

    public static function outboxKey(Server $server): string
    {
        return "chat-outbox:{$server->id}";
    }

    public static function ackKey(ChatMessage $message): string
    {
        return "chat-ack:{$message->id}";
    }
}
