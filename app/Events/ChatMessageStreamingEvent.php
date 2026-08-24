<?php

namespace App\Events;

use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Broadcasts synchronously (ShouldBroadcastNow): streaming deltas are
 * ephemeral and high-frequency — queueing them behind Horizon workers (which
 * are busy running the very chat job producing them) delays every delta by
 * seconds and collapses the streaming experience into one final message.
 * The relay batches deltas at ~300ms, so the inline publish cost is a
 * handful of Reverb HTTP calls per second per active run.
 */
class ChatMessageStreamingEvent extends ChatConversationBroadcastEvent implements ShouldBroadcastNow
{
    public function __construct(
        public string $conversationId,
        public string $streamId,
        public string $delta,
        public string $cumulative,
        public bool $isFinal,
        public ?int $sequence = null,
    ) {}

    protected function conversationId(): string
    {
        return $this->conversationId;
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'chat_conversation_id' => $this->conversationId,
            'stream_id' => $this->streamId,
            'delta' => $this->delta,
            'cumulative' => $this->cumulative,
            'is_final' => $this->isFinal,
            // Monotonic per-run sequence so the UI can drop a stale batch
            // that arrives after a newer cumulative snapshot.
            'sequence' => $this->sequence,
        ];
    }

    public function broadcastAs(): string
    {
        return 'chat.message.streaming';
    }
}
