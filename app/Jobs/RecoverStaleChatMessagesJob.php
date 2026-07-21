<?php

namespace App\Jobs;

use App\Enums\ChatMessageRole;
use App\Models\ChatMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class RecoverStaleChatMessagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function handle(): void
    {
        $staleBefore = now()->subMinute();

        ChatMessage::query()
            ->with('conversation')
            ->where('role', ChatMessageRole::User)
            ->where('delivery_status', 'queued')
            ->where(function (Builder $query) use ($staleBefore): void {
                $query->whereNull('enqueued_at')
                    ->orWhere('enqueued_at', '<=', $staleBefore);
            })
            ->where('sent_at', '<=', $staleBefore)
            ->chunkById(100, function ($messages) use ($staleBefore): void {
                foreach ($messages as $message) {
                    $claimed = ChatMessage::query()
                        ->whereKey($message->getKey())
                        ->where('delivery_status', 'queued')
                        ->where(function (Builder $query) use ($staleBefore): void {
                            $query->whereNull('enqueued_at')
                                ->orWhere('enqueued_at', '<=', $staleBefore);
                        })
                        ->update(['enqueued_at' => now()]);

                    if ($claimed === 0 || ! $message->conversation) {
                        continue;
                    }

                    try {
                        SendAgentChatMessageJob::dispatch($message->conversation, $message);
                    } catch (Throwable $e) {
                        ChatMessage::query()
                            ->whereKey($message->getKey())
                            ->where('delivery_status', 'queued')
                            ->update(['enqueued_at' => null]);

                        Log::warning('Could not recover a stale queued chat message', [
                            'conversation_id' => $message->chat_conversation_id,
                            'message_id' => $message->id,
                            'exception' => $e::class,
                        ]);
                    }
                }
            });
    }
}
