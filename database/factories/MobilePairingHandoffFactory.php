<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\MobilePairingHandoff;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MobilePairingHandoff>
 */
class MobilePairingHandoffFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'agent_id' => Agent::factory(),
            'server_id' => Server::factory(),
            'created_by_user_id' => User::factory(),
            'token_hash' => hash('sha256', fake()->unique()->uuid()),
            'expires_at' => now()->addMinutes(5),
        ];
    }
}
