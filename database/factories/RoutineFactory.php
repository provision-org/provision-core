<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\Routine;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Routine>
 */
class RoutineFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'agent_id' => Agent::factory(),
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'cron_expression' => '0 9 * * *',
            'timezone' => 'UTC',
            'status' => 'active',
            'next_run_at' => now()->addDay(),
        ];
    }

    public function paused(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'paused',
            'next_run_at' => null,
        ]);
    }
}
