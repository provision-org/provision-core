<?php

namespace App\Events;

use App\Models\Approval;

class ApprovalResolvedEvent extends TeamBroadcastEvent
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
            'approval_id' => $this->approval->id,
            'status' => $this->approval->status->value,
            'reviewed_by' => $this->approval->reviewed_by,
        ];
    }

    public function broadcastAs(): string
    {
        return 'approval.resolved';
    }
}
