<?php

namespace Database\Factories;

use App\Enums\TelegramConnectionStatus;
use App\Models\Agent;
use App\Models\AgentTelegramConnection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentTelegramConnection>
 */
class AgentTelegramConnectionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'agent_id' => Agent::factory(),
            'status' => TelegramConnectionStatus::Disconnected,
        ];
    }

    public function connected(): static
    {
        return $this->state(fn (array $attributes) => [
            'bot_token' => fake()->numerify('##########').':'.fake()->regexify('[A-Za-z0-9_-]{35}'),
            'bot_username' => fake()->userName().'_bot',
            'status' => TelegramConnectionStatus::Connected,
        ]);
    }
}
