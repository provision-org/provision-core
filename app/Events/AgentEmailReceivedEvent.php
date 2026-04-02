<?php

namespace App\Events;

class AgentEmailReceivedEvent extends TeamBroadcastEvent
{
    public function __construct(public string $teamId, public string $agentId) {}

    protected function teamId(): string
    {
        return $this->teamId;
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['agent_id' => $this->agentId];
    }

    public function broadcastAs(): string
    {
        return 'agent.email.received';
    }
}
