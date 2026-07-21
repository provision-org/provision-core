<?php

namespace App\Events;

class ChatMessageErrorEvent extends ChatConversationBroadcastEvent
{
    public function __construct(
        public string $chatConversationId,
        public string $errorMessage = 'The agent did not respond in time.',
    ) {}

    protected function conversationId(): string
    {
        return $this->chatConversationId;
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
