<?php

namespace App\Jobs;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Services\OpenClawChatService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CleanupOpenClawChatAttachmentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        public ChatConversation $conversation,
        public ChatMessage $message,
    ) {
        $this->onQueue('chat');
    }

    public function handle(OpenClawChatService $chatService): void
    {
        $chatService->cleanupAttachments($this->conversation, $this->message);
    }
}
