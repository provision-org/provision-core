<?php

namespace App\Events;

use App\Models\Agent;

class WorkspaceUpdatedEvent extends TeamBroadcastEvent
{
    public function __construct(public Agent $agent) {}

    protected function teamId(): string
    {
        return $this->agent->team_id;
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['agent_id' => $this->agent->id];
    }

    public function broadcastAs(): string
    {
        return 'workspace.updated';
    }
}
