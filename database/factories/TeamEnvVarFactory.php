<?php

namespace Database\Factories;

use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\TeamEnvVar>
 */
class TeamEnvVarFactory extends Factory
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
            'key' => fake()->unique()->bothify('??_###_KEY'),
            'value' => fake()->word(),
            'is_secret' => false,
        ];
    }

    /**
     * Indicate that the env var is secret.
     */
    public function secret(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_secret' => true,
        ]);
    }
}
