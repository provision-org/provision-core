<?php

namespace App\Contracts\Modules;

use App\Models\Team;

interface BillingProvider
{
    public function canCreateAgent(Team $team): bool;

    public function getAgentLimit(Team $team): ?int;

    public function cleanupTeam(Team $team): void;

    public function requiresSubscription(): bool;
}
