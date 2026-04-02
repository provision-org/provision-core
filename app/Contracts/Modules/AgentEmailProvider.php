<?php

namespace App\Contracts\Modules;

use App\Models\Agent;
use App\Models\Team;

interface AgentEmailProvider
{
    public function provisionEmail(Agent $agent, Team $team, ?string $prefix = null): ?string;

    public function deprovisionEmail(Agent $agent): void;

    /** @return array<string, mixed> */
    public function getInbox(Agent $agent, int $page = 1): array;

    /** @return array<string, mixed> */
    public function getMessage(Agent $agent, string $messageId): array;
}
