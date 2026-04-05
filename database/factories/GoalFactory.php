<?php

namespace Database\Factories;

use App\Enums\GoalPriority;
use App\Enums\GoalStatus;
use App\Models\Goal;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Goal>
 */
class GoalFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'status' => GoalStatus::Active,
            'priority' => GoalPriority::Medium,
            'progress_pct' => 0,
        ];
    }

    public function achieved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => GoalStatus::Achieved,
            'progress_pct' => 100,
        ]);
    }

    public function abandoned(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => GoalStatus::Abandoned,
        ]);
    }

    public function critical(): static
    {
        return $this->state(fn (array $attributes) => [
            'priority' => GoalPriority::Critical,
        ]);
    }
}
