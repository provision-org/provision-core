<?php

namespace Database\Factories;

use App\Models\GatewayConfig;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GatewayConfig>
 */
class GatewayConfigFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'server_id' => Server::factory(),
            'config_json' => [
                'agents' => ['list' => []],
                'channels' => ['slack' => ['accounts' => []]],
            ],
            'version' => 1,
        ];
    }
}
