<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\Server;
use App\Services\DaemonChatSendService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;

/**
 * Outbox for daemon fast-path chat sends.
 *
 * The daemon long-polls `poll` (BLPOP, bounded wait) and receives at most one
 * send per response; it fires `chat.send` over its loopback gateway WebSocket
 * and reports the outcome to `ack`, which unblocks the waiting
 * DaemonChatSendService. Holding one FPM worker per polling daemon is the
 * cost of sub-second delivery — the wait is capped so pool sizing stays
 * predictable.
 */
class DaemonChatOutboxController extends Controller
{
    private const MAX_WAIT_SECONDS = 15;

    public function poll(Request $request): JsonResponse
    {
        /** @var Server $server */
        $server = $request->get('daemon_server');

        $wait = min(max((int) $request->query('wait', 10), 1), self::MAX_WAIT_SECONDS);

        $entry = Redis::connection()->client()->blpop(
            [DaemonChatSendService::outboxKey($server)],
            $wait,
        );

        if (! is_array($entry) || ! isset($entry[1])) {
            return response()->json(['send' => null]);
        }

        $send = json_decode((string) $entry[1], true);

        // A send that sat in the outbox past the TTL belongs to a request
        // that already fell back to SSH — skip it rather than double-firing
        // a stale run (idempotency would absorb it, but there is no point).
        $queuedAt = is_numeric($send['queued_at'] ?? null) ? (int) $send['queued_at'] : 0;
        if ($queuedAt < now()->subSeconds(DaemonChatSendService::OUTBOX_ENTRY_TTL_SECONDS)->getTimestampMs()) {
            return response()->json(['send' => null]);
        }

        return response()->json(['send' => $send]);
    }

    public function ack(Request $request): JsonResponse
    {
        /** @var Server $server */
        $server = $request->get('daemon_server');

        $validated = $request->validate([
            'message_id' => ['required', 'string', 'max:64'],
            'status' => ['required', 'in:started,error'],
            'run_id' => ['nullable', 'string', 'max:255'],
            'error' => ['nullable', 'string', 'max:500'],
        ]);

        $message = ChatMessage::query()
            ->whereKey($validated['message_id'])
            ->whereHas('conversation.agent', fn ($query) => $query->where('server_id', $server->id))
            ->first();

        if (! $message) {
            return response()->json(['status' => 'unknown-message'], 404);
        }

        $client = Redis::connection()->client();
        $client->rpush(DaemonChatSendService::ackKey($message), json_encode([
            'status' => $validated['status'],
            'run_id' => $validated['run_id'] ?? null,
            'error' => $validated['error'] ?? null,
        ], JSON_THROW_ON_ERROR));
        $client->expire(DaemonChatSendService::ackKey($message), 60);

        return response()->json(['status' => 'ok']);
    }
}
