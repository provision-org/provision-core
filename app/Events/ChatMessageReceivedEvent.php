<?php

namespace App\Events;

use App\Models\ChatMessage;

class ChatMessageReceivedEvent extends ChatConversationBroadcastEvent
{
    public function __construct(public ChatMessage $message) {}

    protected function conversationId(): string
    {
        return $this->message->chat_conversation_id;
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->message->id,
            'chat_conversation_id' => $this->message->chat_conversation_id,
            'role' => $this->message->role->value,
            'content' => $this->message->contentWithUrls(),
            'sent_at' => $this->message->sent_at->toISOString(),
        ];
    }

    public function broadcastAs(): string
    {
        return 'chat.message.received';
    }
}
