<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcasts synchronously (ShouldBroadcastNow): streaming deltas already
 * broadcast inline, and the completion event is what re-enables the chat
 * composer — a hop through the queued `broadcasts` lane added seconds of
 * dead air between the last streamed token and the input unlocking.
 */
abstract class ChatConversationBroadcastEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    abstract protected function conversationId(): string;

    abstract public function broadcastAs(): string;

    public function broadcastOn(): array
    {
        return [new PrivateChannel("chat.conversation.{$this->conversationId()}")];
    }
}
