<?php

namespace Database\Factories;

use App\Enums\DiscordConnectionStatus;
use App\Models\Agent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AgentDiscordConnection>
 */
class AgentDiscordConnectionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'agent_id' => Agent::factory(),
            'status' => DiscordConnectionStatus::Disconnected,
        ];
    }

    public function connected(): static
    {
        return $this->state(fn (array $attributes) => [
            'token' => fake()->regexify('[A-Za-z0-9]{24}\.[A-Za-z0-9_-]{6}\.[A-Za-z0-9_-]{27}'),
            'bot_username' => fake()->userName().'Bot',
            'application_id' => fake()->numerify('##################'),
            'guild_id' => fake()->numerify('##################'),
            'status' => DiscordConnectionStatus::Connected,
        ]);
    }
}
