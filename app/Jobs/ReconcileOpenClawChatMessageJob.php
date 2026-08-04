<?php

namespace App\Jobs;

use App\Enums\ChatMessageRole;
use App\Events\ChatMessageErrorEvent;
use App\Events\ChatMessageReceivedEvent;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Services\OpenClawChatService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReconcileOpenClawChatMessageJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const MAX_RECOVERY_MINUTES = 60;

    private const RECOVERY_TIMEOUT_MESSAGE = 'The agent did not finish this response within 60 minutes.';

    public int $tries = 3;

    public int $timeout = 90;

    public int $uniqueFor = 120;

    public function __construct(
        public ChatConversation $conversation,
        public ChatMessage $userMessage,
    ) {
        $this->onQueue('chat');
    }

    public function uniqueId(): string
    {
        return "openclaw-chat-reconcile:{$this->userMessage->id}";
    }

    public function handle(OpenClawChatService $chatService): void
    {
        $this->userMessage->refresh();
        if (! in_array($this->userMessage->delivery_status, ['queued', 'running'], true)
            || ! $this->userMessage->upstream_run_id) {
            return;
        }

        if ($this->recoveryWindowExpired()) {
            $this->finishWithoutReply('failed', self::RECOVERY_TIMEOUT_MESSAGE);

            return;
        }

        try {
            $result = $chatService->reconcile($this->conversation, $this->userMessage);
        } catch (Throwable $exception) {
            Log::warning('OpenClaw chat transcript reconciliation failed', [
                'conversation_id' => $this->conversation->id,
                'message_id' => $this->userMessage->id,
                'exception' => $exception::class,
            ]);

            $this->scheduleNextAttempt(60);

            return;
        }

        $this->conversation->forceFill(['last_reconciled_at' => now()])->save();

        if ($result['reply'] !== null) {
            $this->complete($result);

            return;
        }

        if ($result['aborted']) {
            $this->finishWithoutReply('aborted', 'Response stopped.');

            return;
        }

        if ($result['active']) {
            $this->scheduleNextAttempt(30);

            return;
        }

        $startedAt = $this->userMessage->outbound_to_agent_at ?? $this->userMessage->sent_at;
        if ($startedAt && $startedAt->gt(now()->subMinutes(2))) {
            $this->scheduleNextAttempt(10);

            return;
        }

        $this->finishWithoutReply(
            'failed',
            'The agent stopped processing this request without returning a reply.',
        );
    }

    /**
     * @param  array{run_id: string, reply: array{upstream_id: string, content: list<array<string, mixed>>}}  $result
     */
    private function complete(array $result): void
    {
        $assistantMessage = DB::transaction(function () use ($result): ?ChatMessage {
            $message = ChatMessage::query()
                ->whereKey($this->userMessage->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($message->delivery_status, ['queued', 'running'], true)) {
                return null;
            }

            $assistant = $this->conversation->messages()->firstOrCreate([
                'upstream_id' => $result['reply']['upstream_id'],
            ], [
                'role' => ChatMessageRole::Assistant,
                'reply_to_message_id' => $message->id,
                'content' => $result['reply']['content'],
                'sent_at' => now(),
            ]);

            $message->forceFill([
                'delivery_status' => 'completed',
                'delivery_error' => null,
                'upstream_run_id' => $result['run_id'],
                'last_gateway_event_at' => now(),
            ])->save();

            return $assistant;
        });

        if (! $assistantMessage) {
            return;
        }

        $this->conversation->forceFill([
            'last_message_at' => $assistantMessage->sent_at,
            'last_reconciled_at' => now(),
        ])->save();

        if ($assistantMessage->wasRecentlyCreated) {
            $this->broadcastSafely(new ChatMessageReceivedEvent($assistantMessage));
        }

        CleanupOpenClawChatAttachmentsJob::dispatch($this->conversation, $this->userMessage);
    }

    private function finishWithoutReply(string $status, string $message): void
    {
        $updated = ChatMessage::query()
            ->whereKey($this->userMessage->id)
            ->whereIn('delivery_status', ['queued', 'running'])
            ->update([
                'delivery_status' => $status,
                'delivery_error' => $message,
                'last_gateway_event_at' => now(),
            ]);

        if ($updated === 0) {
            return;
        }

        $this->broadcastSafely(new ChatMessageErrorEvent($this->conversation->id, $message));
        CleanupOpenClawChatAttachmentsJob::dispatch($this->conversation, $this->userMessage);
    }

    private function scheduleNextAttempt(int $delaySeconds): void
    {
        $this->userMessage->refresh();
        if (! in_array($this->userMessage->delivery_status, ['queued', 'running'], true)) {
            return;
        }

        if ($this->recoveryWindowExpired()) {
            $this->finishWithoutReply('failed', self::RECOVERY_TIMEOUT_MESSAGE);

            return;
        }

        self::dispatch($this->conversation, $this->userMessage)
            ->delay(now()->addSeconds($delaySeconds));
    }

    private function recoveryWindowExpired(): bool
    {
        $startedAt = $this->userMessage->outbound_to_agent_at ?? $this->userMessage->sent_at;

        return $startedAt?->lte(now()->subMinutes(self::MAX_RECOVERY_MINUTES)) ?? false;
    }

    private function broadcastSafely(object $event): void
    {
        try {
            event($event);
        } catch (Throwable $exception) {
            Log::warning('Reconciled OpenClaw chat update could not be broadcast', [
                'conversation_id' => $this->conversation->id,
                'event' => $event::class,
                'exception' => $exception::class,
            ]);
        }
    }
}
