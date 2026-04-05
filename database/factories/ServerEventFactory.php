<?php

namespace Database\Factories;

use App\Models\Server;
use App\Models\ServerEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServerEvent>
 */
class ServerEventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'server_id' => Server::factory(),
            'event' => fake()->randomElement([
                'provisioning_started',
                'setup_complete',
                'health_check',
                'gateway_restarted',
                'provisioning_timeout',
            ]),
            'payload' => [],
        ];
    }
}
