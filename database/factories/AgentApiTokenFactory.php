<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\AgentApiToken;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AgentApiToken>
 */
class AgentApiTokenFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'agent_id' => Agent::factory(),
            'team_id' => Team::factory(),
            'name' => 'default',
            'token_hash' => hash('sha256', 'prov_'.Str::random(48)),
        ];
    }
}
