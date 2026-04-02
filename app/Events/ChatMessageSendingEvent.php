<?php

namespace App\Events;

class ChatMessageSendingEvent extends TeamBroadcastEvent
{
    public function __construct(
        private string $teamId,
        public string $chatConversationId,
        public string $agentId,
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
            'agent_id' => $this->agentId,
        ];
    }

    public function broadcastAs(): string
    {
        return 'chat.message.sending';
    }
}
