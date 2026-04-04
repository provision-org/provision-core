<?php

namespace App\Events;

use App\Models\Task;

class TaskStatusChangedEvent extends TeamBroadcastEvent
{
    public function __construct(
        public Task $task,
        public string $oldStatus,
        public string $newStatus,
    ) {}

    protected function teamId(): string
    {
        return $this->task->team_id;
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'task_id' => $this->task->id,
            'old_status' => $this->oldStatus,
            'new_status' => $this->newStatus,
            'agent_name' => $this->task->agent?->name,
        ];
    }

    public function broadcastAs(): string
    {
        return 'task.status_changed';
    }
}
