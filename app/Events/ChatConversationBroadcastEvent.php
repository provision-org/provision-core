<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

abstract class ChatConversationBroadcastEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    abstract protected function conversationId(): string;

    abstract public function broadcastAs(): string;

    public function broadcastOn(): array
    {
        return [new PrivateChannel("chat.conversation.{$this->conversationId()}")];
    }

    public function broadcastQueue(): string
    {
        return 'broadcasts';
    }
}
