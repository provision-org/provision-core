<?php

namespace App\Events;

use App\Models\Approval;

class ApprovalRequestedEvent extends TeamBroadcastEvent
{
    public function __construct(public Approval $approval) {}

    protected function teamId(): string
    {
        return $this->approval->team_id;
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->approval->id,
            'type' => $this->approval->type->value,
            'title' => $this->approval->title,
            'agent_name' => $this->approval->requestingAgent?->name,
        ];
    }

    public function broadcastAs(): string
    {
        return 'approval.requested';
    }
}
