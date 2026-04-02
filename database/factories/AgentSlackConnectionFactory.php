<?php

namespace Database\Factories;

use App\Enums\SlackConnectionStatus;
use App\Models\Agent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AgentSlackConnection>
 */
class AgentSlackConnectionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'agent_id' => Agent::factory(),
            'status' => SlackConnectionStatus::Disconnected,
        ];
    }

    public function connected(): static
    {
        return $this->state(fn (array $attributes) => [
            'bot_token' => 'xoxb-'.fake()->sha256(),
            'app_token' => 'xapp-'.fake()->sha256(),
            'status' => SlackConnectionStatus::Connected,
            'slack_team_id' => 'T'.fake()->bothify('??########'),
            'slack_bot_user_id' => 'U'.fake()->bothify('??########'),
        ]);
    }

    public function automated(): static
    {
        return $this->connected()->state(fn (array $attributes) => [
            'slack_app_id' => 'A'.fake()->bothify('??########'),
            'client_id' => fake()->numerify('################.################'),
            'client_secret' => fake()->sha256(),
            'signing_secret' => fake()->sha256(),
            'is_automated' => true,
        ]);
    }
}
