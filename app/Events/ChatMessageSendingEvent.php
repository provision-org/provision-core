<?php

namespace App\Events;

class ChatMessageSendingEvent extends ChatConversationBroadcastEvent
{
    public function __construct(
        public string $chatConversationId,
        public string $agentId,
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
            'agent_id' => $this->agentId,
        ];
    }

    public function broadcastAs(): string
    {
        return 'chat.message.sending';
    }
}
