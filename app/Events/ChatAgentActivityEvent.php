<?php

namespace App\Events;

class ChatAgentActivityEvent extends ChatConversationBroadcastEvent
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $conversationId,
        public array $payload,
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
            ...$this->payload,
        ];
    }

    public function broadcastAs(): string
    {
        return 'chat.agent.activity';
    }
}
