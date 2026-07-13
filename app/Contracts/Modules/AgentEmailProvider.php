<?php

namespace App\Contracts\Modules;

use App\Models\Agent;
use App\Models\Team;

interface AgentEmailProvider
{
    public function provisionEmail(Agent $agent, Team $team, ?string $prefix = null, ?string $domain = null): ?string;

    /**
     * Move an existing agent's email to a different (verified) domain.
     * Returns the new email address, or null on failure.
     */
    public function changeEmailDomain(Agent $agent, Team $team, string $domain): ?string;

    /**
     * The domains an agent's email may use for this team.
     *
     * @return list<array{name: string, is_default: bool, is_verified: bool}>
     */
    public function availableDomains(Team $team): array;

    public function deprovisionEmail(Agent $agent): void;

    /** @return array<string, mixed> */
    public function getInbox(Agent $agent, int $page = 1): array;

    /** @return array<string, mixed> */
    public function getMessage(Agent $agent, string $messageId): array;
}
