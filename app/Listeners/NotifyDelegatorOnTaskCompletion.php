<?php

namespace App\Listeners;

use App\Events\TaskStatusChangedEvent;
use App\Jobs\NotifyDelegatorAboutTaskCompletionJob;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Events\Attribute\AsListener;

class NotifyDelegatorOnTaskCompletion implements ShouldHandleEventsAfterCommit
{
    #[AsListener(TaskStatusChangedEvent::class)]
    public function handle(TaskStatusChangedEvent $event): void
    {
        if (! in_array($event->newStatus, ['done', 'failed', 'blocked'], true)) {
            return;
        }

        if (! $event->task->delegated_by) {
            return;
        }

        $event->task->loadMissing('delegatedByAgent');
        $delegator = $event->task->delegatedByAgent;

        if (! $delegator) {
            return;
        }

        NotifyDelegatorAboutTaskCompletionJob::dispatch($delegator, $event->task, $event->newStatus);
    }
}
