<?php

namespace Database\Factories;

use App\Enums\UsageSource;
use App\Models\Agent;
use App\Models\Team;
use App\Models\UsageEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UsageEvent>
 */
class UsageEventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'agent_id' => Agent::factory(),
            'model' => 'anthropic/claude-haiku-4-5',
            'input_tokens' => fake()->numberBetween(100, 50000),
            'output_tokens' => fake()->numberBetween(50, 5000),
            'source' => UsageSource::Daemon,
            'created_at' => now(),
        ];
    }

    public function fromChannel(): static
    {
        return $this->state(fn (array $attributes) => [
            'source' => UsageSource::Channel,
        ]);
    }

    public function fromWebChat(): static
    {
        return $this->state(fn (array $attributes) => [
            'source' => UsageSource::WebChat,
        ]);
    }
}
