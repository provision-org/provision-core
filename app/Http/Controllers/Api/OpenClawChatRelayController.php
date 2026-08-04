<?php

namespace App\Http\Controllers\Api;

use App\Enums\ChatMessageRole;
use App\Enums\HarnessType;
use App\Events\ChatAgentActivityEvent;
use App\Events\ChatMessageErrorEvent;
use App\Events\ChatMessageStreamingEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\IngestOpenClawChatEventsRequest;
use App\Http\Requests\Api\SyncOpenClawSessionsRequest;
use App\Jobs\CleanupOpenClawChatAttachmentsJob;
use App\Jobs\ReconcileOpenClawChatMessageJob;
use App\Jobs\SyncOpenClawConversationJob;
use App\Models\Agent;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\Server;
use App\Services\OpenClawSessionSyncService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class OpenClawChatRelayController extends Controller
{
    public function events(IngestOpenClawChatEventsRequest $request): JsonResponse
    {
        /** @var Server $server */
        $server = $request->get('daemon_server');
        $accepted = 0;

        foreach ($request->validated('events') as $event) {
            $agent = Agent::query()
                ->where('server_id', $server->id)
                ->where('harness_type', HarnessType::OpenClaw)
                ->where('harness_agent_id', $event['agent_id'])
                ->first();
            if (! $agent || ! Str::startsWith($event['session_key'], "agent:{$agent->harness_agent_id}:")) {
                continue;
            }

            $conversation = ChatConversation::query()
                ->where('agent_id', $agent->id)
                ->where('session_key', $event['session_key'])
                ->first();
            if (! $conversation) {
                continue;
            }

            $this->handleEvent($conversation, $event);
            $accepted++;
        }

        return response()->json(['status' => 'ok', 'accepted' => $accepted]);
    }

    public function sessions(
        SyncOpenClawSessionsRequest $request,
        OpenClawSessionSyncService $syncService,
    ): JsonResponse {
        /** @var Server $server */
        $server = $request->get('daemon_server');
        $ingested = $syncService->ingestSnapshot($server, $request->validated('sessions'));

        return response()->json(['status' => 'ok', 'ingested' => $ingested]);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function handleEvent(ChatConversation $conversation, array $event): void
    {
        $message = $this->activeMessage($conversation, $event);

        if ($message) {
            $sequence = is_int($event['sequence'] ?? null) ? $event['sequence'] : null;
            if (($event['event'] ?? null) === 'chat'
                && ($event['state'] ?? null) === 'delta'
                && ! $this->recordProgress($message, $sequence)) {
                return;
            }

            ChatMessage::query()->whereKey($message->id)->update([
                'last_gateway_event_at' => now(),
            ]);
        }

        match ($event['event']) {
            'chat' => $this->handleChatEvent($conversation, $message, $event),
            'session.message' => $this->handleSessionMessage($conversation, $message, $event),
            'session.tool' => $this->handleToolEvent($conversation, $event),
            'sessions.changed' => $this->handleSessionChanged($conversation, $message, $event),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function activeMessage(ChatConversation $conversation, array $event): ?ChatMessage
    {
        $runId = $event['run_id'] ?? null;
        $idempotencyKey = $event['idempotency_key'] ?? null;
        if (is_string($idempotencyKey)
            && preg_match('/^provision-chat:([0-9a-z]{26})(?::user)?$/i', $idempotencyKey, $matches) === 1) {
            $matched = $conversation->messages()
                ->whereKey($matches[1])
                ->where('role', ChatMessageRole::User)
                ->whereIn('delivery_status', ['queued', 'running'])
                ->first();
            if ($matched) {
                if (is_string($runId) && $runId !== '') {
                    if (is_string($matched->upstream_run_id)
                        && $matched->upstream_run_id !== ''
                        && ! hash_equals($matched->upstream_run_id, $runId)) {
                        return null;
                    }

                    if (! $matched->upstream_run_id) {
                        $matched->forceFill([
                            'delivery_status' => 'running',
                            'upstream_run_id' => $runId,
                        ])->save();
                    }
                }

                return $matched;
            }
        }

        if (is_string($runId) && $runId !== '') {
            $matched = $conversation->messages()
                ->where('role', ChatMessageRole::User)
                ->where('upstream_run_id', $runId)
                ->whereIn('delivery_status', ['queued', 'running'])
                ->first();
            if ($matched) {
                return $matched;
            }
        }

        if (! is_string($runId) || $runId === '') {
            return null;
        }

        $candidates = $conversation->messages()
            ->where('role', ChatMessageRole::User)
            ->where('delivery_status', 'running')
            ->whereNull('upstream_run_id')
            ->latest('sent_at')
            ->latest('id')
            ->limit(2)
            ->get();
        if ($candidates->count() !== 1) {
            return null;
        }

        $matched = $candidates->first();
        $claimed = ChatMessage::query()
            ->whereKey($matched->id)
            ->where('delivery_status', 'running')
            ->whereNull('upstream_run_id')
            ->update([
                'upstream_run_id' => $runId,
                'last_gateway_event_at' => now(),
            ]);

        return $claimed > 0 ? $matched->refresh() : null;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function handleChatEvent(
        ChatConversation $conversation,
        ?ChatMessage $message,
        array $event,
    ): void {
        $state = $event['state'] ?? null;

        if ($state === 'delta' && $message) {
            $delta = is_string($event['delta'] ?? null) ? $event['delta'] : '';
            $cumulative = is_string($event['cumulative'] ?? null)
                ? $event['cumulative']
                : $delta;
            if ($delta !== '' || $cumulative !== '') {
                $this->broadcastSafely(new ChatMessageStreamingEvent(
                    $conversation->id,
                    (string) ($event['run_id'] ?? $message->upstream_run_id ?? $message->id),
                    $delta,
                    $cumulative,
                    false,
                ), $conversation);
            }

            return;
        }

        if ($state === 'final' && $message) {
            ReconcileOpenClawChatMessageJob::dispatch($conversation, $message);

            return;
        }

        if ($state === 'aborted' && $message) {
            $this->finishMessage($conversation, $message, 'aborted', 'Response stopped.');

            return;
        }

        if ($state === 'error' && $message) {
            $this->finishMessage(
                $conversation,
                $message,
                'failed',
                $this->safeErrorMessage($event['error_kind'] ?? null),
            );
        }
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function handleSessionMessage(
        ChatConversation $conversation,
        ?ChatMessage $message,
        array $event,
    ): void {
        if (($event['role'] ?? null) === 'assistant' && $message) {
            ReconcileOpenClawChatMessageJob::dispatch($conversation, $message);

            return;
        }

        if (($event['role'] ?? null) === 'user' && $message) {
            $message->forceFill([
                'outbound_to_agent_at' => $message->outbound_to_agent_at ?? now(),
                'last_gateway_event_at' => now(),
            ])->save();

            return;
        }

        if ($conversation->source === 'openclaw') {
            SyncOpenClawConversationJob::dispatch($conversation);
        }
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function handleToolEvent(ChatConversation $conversation, array $event): void
    {
        $tool = is_string($event['tool'] ?? null) ? $event['tool'] : null;
        $phase = is_string($event['phase'] ?? null) ? $event['phase'] : null;
        $label = is_string($event['label'] ?? null) ? $event['label'] : null;
        $label ??= $tool ? Str::lower(Str::headline($tool)) : 'working';

        $this->broadcastSafely(new ChatAgentActivityEvent($conversation->id, [
            'kind' => 'tool',
            'tool' => $tool,
            'phase' => $phase,
            'label' => Str::limit($label, 255, ''),
        ]), $conversation);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function handleSessionChanged(
        ChatConversation $conversation,
        ?ChatMessage $message,
        array $event,
    ): void {
        if ($message && ($event['has_active_run'] ?? null) === false) {
            ReconcileOpenClawChatMessageJob::dispatch($conversation, $message);
        }
    }

    private function recordProgress(ChatMessage $message, ?int $sequence): bool
    {
        if ($sequence === null) {
            return true;
        }

        return ChatMessage::query()
            ->whereKey($message->id)
            ->where(function (Builder $query) use ($sequence): void {
                $query->whereNull('upstream_event_sequence')
                    ->orWhere('upstream_event_sequence', '<', $sequence);
            })
            ->update([
                'upstream_event_sequence' => $sequence,
                'last_gateway_event_at' => now(),
            ]) > 0;
    }

    private function finishMessage(
        ChatConversation $conversation,
        ChatMessage $message,
        string $status,
        string $error,
    ): void {
        $updated = ChatMessage::query()
            ->whereKey($message->id)
            ->whereIn('delivery_status', ['queued', 'running'])
            ->update([
                'delivery_status' => $status,
                'delivery_error' => $error,
                'last_gateway_event_at' => now(),
            ]);
        if ($updated === 0) {
            return;
        }

        $this->broadcastSafely(new ChatMessageErrorEvent($conversation->id, $error), $conversation);
        CleanupOpenClawChatAttachmentsJob::dispatch($conversation, $message);
    }

    private function safeErrorMessage(mixed $kind): string
    {
        return match ($kind) {
            'rate_limit' => 'The model is temporarily rate limited. Please try again.',
            'context_length' => 'This conversation is too large for the selected model.',
            'refusal' => 'The agent could not complete that request.',
            default => 'The agent encountered an error while processing this request.',
        };
    }

    private function broadcastSafely(object $event, ChatConversation $conversation): void
    {
        try {
            event($event);
        } catch (Throwable $exception) {
            Log::warning('OpenClaw relay event could not be broadcast', [
                'conversation_id' => $conversation->id,
                'event' => $event::class,
                'exception' => $exception::class,
            ]);
        }
    }
}
