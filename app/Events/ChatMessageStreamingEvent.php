<?php

namespace App\Events;

class ChatMessageStreamingEvent extends ChatConversationBroadcastEvent
{
    public function __construct(
        public string $conversationId,
        public string $streamId,
        public string $delta,
        public string $cumulative,
        public bool $isFinal,
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
        ];
    }

    public function broadcastAs(): string
    {
        return 'chat.message.streaming';
    }
}
