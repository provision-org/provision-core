<?php

namespace App\Jobs;

use App\Models\ChatConversation;
use App\Services\OpenClawSessionSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncOpenClawConversationJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 90;

    public int $uniqueFor = 60;

    public function __construct(public ChatConversation $conversation)
    {
        $this->onQueue('chat');
    }

    public function uniqueId(): string
    {
        return "openclaw-conversation-sync:{$this->conversation->id}";
    }

    public function handle(OpenClawSessionSyncService $syncService): void
    {
        $syncService->syncTranscript($this->conversation);
    }
}
