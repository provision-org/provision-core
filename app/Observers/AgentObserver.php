<?php

namespace App\Observers;

use App\Models\Agent;
use App\Models\AgentWebConnection;

class AgentObserver
{
    public function created(Agent $agent): void
    {
        // Every agent gets a provision-web channel connection by default —
        // no setup ceremony, the web chat is the primary surface.
        if (! $agent->harness_agent_id) {
            return;
        }

        if ($agent->webConnection()->exists()) {
            return;
        }

        AgentWebConnection::provisionFor($agent);
    }
}
