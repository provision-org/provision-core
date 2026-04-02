<?php

namespace App\Contracts\Modules;

use App\Models\Agent;

interface AgentProxyProvider
{
    public function buildProxyScript(Agent $agent): string;

    /** @return array<int, string> */
    public function proxyDomains(): array;
}
