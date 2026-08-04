<?php

namespace App\Services;

use App\Enums\ChatMessageRole;
use App\Enums\HarnessType;
use App\Events\ChatMessageReceivedEvent;
use App\Jobs\ReconcileOpenClawChatMessageJob;
use App\Jobs\SyncOpenClawConversationJob;
use App\Models\Agent;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\OpenClawSessionDiscovery;
use App\Models\Server;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class OpenClawSessionSyncService
{
    public function __construct(private readonly OpenClawChatService $chatService) {}

    /**
     * @param  list<array<string, mixed>>  $sessions
     */
    public function ingestSnapshot(Server $server, array $sessions): int
    {
        $agents = $server->agents()
            ->where('harness_type', HarnessType::OpenClaw)
            ->whereNotNull('harness_agent_id')
            ->get()
            ->keyBy('harness_agent_id');
        $ingested = 0;

        foreach ($sessions as $session) {
            $agentId = $session['agentId'] ?? null;
            $sessionKey = $session['key'] ?? null;
            if (! is_string($agentId) || ! is_string($sessionKey) || strlen($sessionKey) > 255) {
                continue;
            }

            /** @var Agent|null $agent */
            $agent = $agents->get($agentId);
            if (! $agent || ! Str::startsWith($sessionKey, "agent:{$agent->harness_agent_id}:")) {
                continue;
            }

            $updatedAt = $this->timestamp($session['updatedAt'] ?? null);
            $knownConversation = ChatConversation::query()
                ->where('agent_id', $agent->id)
                ->where('session_key', $sessionKey)
                ->first();
            if ($knownConversation) {
                OpenClawSessionDiscovery::query()
                    ->where('server_id', $server->id)
                    ->where('session_key', $sessionKey)
                    ->update([
                        'has_active_run' => ($session['hasActiveRun'] ?? false) === true,
                        'active_run_ids' => collect($session['activeRunIds'] ?? [])
                            ->filter(fn (mixed $runId) => is_string($runId) && $runId !== '')
                            ->take(20)
                            ->values()
                            ->all(),
                        'upstream_updated_at' => $updatedAt,
                        'discovered_at' => now(),
                    ]);

                $activeMessage = $knownConversation->messages()
                    ->whereIn('delivery_status', ['queued', 'running'])
                    ->latest('sent_at')
                    ->first();
                if ($activeMessage?->upstream_run_id) {
                    ReconcileOpenClawChatMessageJob::dispatch($knownConversation, $activeMessage);
                } elseif ($knownConversation->source === 'openclaw'
                    && ($knownConversation->last_reconciled_at === null
                        || ($updatedAt !== null && $updatedAt->isAfter($knownConversation->last_reconciled_at)))) {
                    SyncOpenClawConversationJob::dispatch($knownConversation);
                }

                continue;
            }

            if (! $this->isEligibleSession($agent, $session)) {
                continue;
            }

            OpenClawSessionDiscovery::query()->updateOrCreate([
                'server_id' => $server->id,
                'session_key' => $sessionKey,
            ], [
                'agent_id' => $agent->id,
                'kind' => $this->boundedString($session['kind'] ?? 'unknown', 32) ?? 'unknown',
                'channel' => $this->boundedString($session['channel'] ?? null, 64),
                'chat_type' => $this->boundedString($session['chatType'] ?? null, 64),
                'title' => $this->sessionTitle($session),
                'preview' => $this->boundedString($session['lastMessagePreview'] ?? null, 500),
                'has_active_run' => ($session['hasActiveRun'] ?? false) === true,
                'active_run_ids' => collect($session['activeRunIds'] ?? [])
                    ->filter(fn (mixed $runId) => is_string($runId) && $runId !== '')
                    ->take(20)
                    ->values()
                    ->all(),
                'upstream_updated_at' => $updatedAt,
                'discovered_at' => now(),
            ]);
            $ingested++;
        }

        return $ingested;
    }

    /**
     * Discover directly from the Gateway when an admin explicitly refreshes
     * the list. The daemon snapshot remains the normal background path.
     */
    public function refreshAgent(Agent $agent): int
    {
        $agent->loadMissing('server');
        if (! $agent->server || ! is_string($agent->harness_agent_id)) {
            throw new RuntimeException('The agent Gateway is not available.');
        }

        $sessions = collect($this->chatService->listSessions($agent))
            ->map(fn (array $session) => [
                ...$session,
                'agentId' => $agent->harness_agent_id,
            ])
            ->all();

        return $this->ingestSnapshot($agent->server, $sessions);
    }

    public function claimAndImport(OpenClawSessionDiscovery $discovery, User $user): ChatConversation
    {
        $conversation = null;
        $createdConversation = false;

        try {
            $conversation = DB::transaction(function () use ($discovery, $user, &$createdConversation): ChatConversation {
                $locked = OpenClawSessionDiscovery::query()
                    ->whereKey($discovery->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($locked->chat_conversation_id !== null || $locked->claimed_by_user_id !== null) {
                    if ($locked->claimed_by_user_id === $user->id && $locked->conversation) {
                        return $locked->conversation;
                    }

                    throw new RuntimeException('This server session has already been imported.');
                }

                $conversation = ChatConversation::query()->create([
                    'agent_id' => $locked->agent_id,
                    'user_id' => $user->id,
                    'title' => $locked->title ?: 'Imported server chat',
                    'session_key' => $locked->session_key,
                    'source' => 'openclaw',
                    'source_channel' => $locked->channel,
                    'is_read_only' => ! $locked->canSend(),
                    'last_message_at' => $locked->upstream_updated_at ?? now(),
                ]);
                $createdConversation = true;

                $locked->forceFill([
                    'claimed_by_user_id' => $user->id,
                    'chat_conversation_id' => $conversation->id,
                ])->save();

                return $conversation;
            });

            if (! $createdConversation) {
                return $conversation->refresh();
            }

            $this->syncTranscript($conversation);

            return $conversation->refresh();
        } catch (Throwable $exception) {
            if ($createdConversation && $conversation && ! $conversation->last_reconciled_at) {
                DB::transaction(function () use ($conversation, $user): void {
                    OpenClawSessionDiscovery::query()
                        ->where('chat_conversation_id', $conversation->id)
                        ->where('claimed_by_user_id', $user->id)
                        ->update([
                            'claimed_by_user_id' => null,
                            'chat_conversation_id' => null,
                        ]);
                    $conversation->delete();
                });
            }

            if ($exception instanceof QueryException) {
                throw new RuntimeException('This server session has already been imported.');
            }

            throw $exception;
        }
    }

    /**
     * Synchronize one canonical transcript idempotently. Returns newly-created
     * messages so callers can choose whether to broadcast additional context.
     *
     * @return list<ChatMessage>
     */
    public function syncTranscript(ChatConversation $conversation): array
    {
        $projected = $this->chatService->transcript($conversation);
        $created = [];
        $lastUserId = null;

        foreach ($projected as $entry) {
            $message = DB::transaction(function () use ($conversation, $entry, &$lastUserId): ChatMessage {
                $existing = $this->messageForProvisionIdempotencyKey(
                    $conversation,
                    $entry['idempotency_key'],
                );
                $sentAt = $this->timestamp($entry['timestamp_ms']) ?? now();
                $role = $entry['role'] === 'assistant'
                    ? ChatMessageRole::Assistant
                    : ChatMessageRole::User;

                if ($existing) {
                    $existing->forceFill([
                        'upstream_id' => $existing->upstream_id ?? $entry['upstream_id'],
                        'upstream_event_sequence' => $existing->upstream_event_sequence ?? $entry['sequence'],
                        'outbound_to_agent_at' => $existing->outbound_to_agent_at ?? $sentAt,
                    ])->save();
                    $message = $existing;
                } else {
                    $message = $conversation->messages()->firstOrCreate([
                        'upstream_id' => $entry['upstream_id'],
                    ], [
                        'role' => $role,
                        'reply_to_message_id' => $role === ChatMessageRole::Assistant ? $lastUserId : null,
                        'content' => $entry['content'],
                        'sent_at' => $sentAt,
                        'delivery_status' => $role === ChatMessageRole::User ? 'completed' : null,
                        'outbound_to_agent_at' => $role === ChatMessageRole::User ? $sentAt : null,
                        'upstream_event_sequence' => $entry['sequence'],
                    ]);
                }

                if ($role === ChatMessageRole::User) {
                    $lastUserId = $message->id;
                }

                return $message;
            });

            if ($message->wasRecentlyCreated) {
                $created[] = $message;
            }
        }

        $lastMessageAt = $conversation->messages()->max('sent_at');
        $conversation->forceFill([
            'last_message_at' => $lastMessageAt ?? $conversation->last_message_at,
            'last_reconciled_at' => now(),
        ])->save();

        foreach ($created as $message) {
            $this->broadcastSafely(new ChatMessageReceivedEvent($message), $conversation);
        }

        return $created;
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function isEligibleSession(Agent $agent, array $session): bool
    {
        $key = $session['key'] ?? null;
        $kind = $session['kind'] ?? null;
        if (! is_string($key)
            || ! Str::startsWith($key, "agent:{$agent->harness_agent_id}:")
            || ! in_array($kind, ['direct', 'group'], true)) {
            return false;
        }

        $lowerKey = Str::lower($key);

        return ! Str::contains($lowerKey, [':cron:', ':subagent:', ':acp:', ':task:'])
            && empty($session['spawnedBy'])
            && empty($session['subagentRole']);
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function sessionTitle(array $session): string
    {
        foreach (['label', 'displayName', 'derivedTitle', 'subject'] as $key) {
            $value = $this->boundedString($session[$key] ?? null, 255);
            if ($value !== null) {
                return $value;
            }
        }

        return 'Server chat';
    }

    private function boundedString(mixed $value, int $length): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return Str::limit(trim($value), $length, '');
    }

    private function timestamp(mixed $value): ?CarbonImmutable
    {
        if (! is_int($value) && ! is_float($value)) {
            return null;
        }

        $milliseconds = (int) $value;
        if ($milliseconds <= 0) {
            return null;
        }

        return CarbonImmutable::createFromTimestampMs($milliseconds);
    }

    private function messageForProvisionIdempotencyKey(
        ChatConversation $conversation,
        ?string $idempotencyKey,
    ): ?ChatMessage {
        if (! is_string($idempotencyKey)
            || preg_match('/^provision-chat:([0-9a-z]{26})(?::user)?$/i', $idempotencyKey, $matches) !== 1) {
            return null;
        }

        return $conversation->messages()
            ->whereKey($matches[1])
            ->where('role', ChatMessageRole::User)
            ->first();
    }

    private function broadcastSafely(object $event, ChatConversation $conversation): void
    {
        try {
            event($event);
        } catch (Throwable $exception) {
            Log::warning('Imported OpenClaw chat update could not be broadcast', [
                'conversation_id' => $conversation->id,
                'event' => $event::class,
                'exception' => $exception::class,
            ]);
        }
    }
}
