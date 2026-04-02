<?php

namespace App\Contracts\Modules;

use App\Models\Agent;

interface AgentBrowserProvider
{
    public function getBrowserUrl(Agent $agent): ?string;

    public function buildDisplayScript(Agent $agent): string;
}
