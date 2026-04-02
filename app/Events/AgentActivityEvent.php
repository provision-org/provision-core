<?php

namespace App\Events;

use App\Models\AgentActivity;

class AgentActivityEvent extends TeamBroadcastEvent
{
    public function __construct(public AgentActivity $activity)
    {
        $this->activity->loadMissing('agent');
    }

    protected function teamId(): string
    {
        return $this->activity->agent->team_id;
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->activity->id,
            'agent_id' => $this->activity->agent_id,
            'agent_name' => $this->activity->agent->name,
            'type' => $this->activity->type,
            'channel' => $this->activity->channel,
            'summary' => $this->activity->summary,
            'created_at' => $this->activity->created_at->toISOString(),
        ];
    }

    public function broadcastAs(): string
    {
        return 'agent.activity';
    }
}
