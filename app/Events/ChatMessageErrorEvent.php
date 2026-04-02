<?php

namespace App\Events;

class ChatMessageErrorEvent extends TeamBroadcastEvent
{
    public function __construct(
        private string $teamId,
        public string $chatConversationId,
        public string $errorMessage = 'The agent did not respond in time.',
    ) {}

    protected function teamId(): string
    {
        return $this->teamId;
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'chat_conversation_id' => $this->chatConversationId,
            'error_message' => $this->errorMessage,
        ];
    }

    public function broadcastAs(): string
    {
        return 'chat.message.error';
    }
}
