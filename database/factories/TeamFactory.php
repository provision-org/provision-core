<?php

namespace Database\Factories;

use App\Enums\HarnessType;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Team>
 */
class TeamFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->company(),
            'personal_team' => false,
            'harness_type' => HarnessType::Hermes,
            'cloud_provider' => config('cloud.default_provider', 'linode'),
        ];
    }

    /**
     * Indicate that the team is a personal team.
     */
    public function personalTeam(): static
    {
        return $this->state(fn (array $attributes) => [
            'personal_team' => true,
        ]);
    }

    public function withCompanyDetails(): static
    {
        return $this->state(fn (array $attributes) => [
            'company_name' => fake()->company(),
            'company_url' => fake()->url(),
            'company_description' => fake()->paragraph(),
            'target_market' => fake()->words(3, true),
        ]);
    }

    /**
     * Set the team as subscribed (requires billing module).
     */
    public function subscribed(string $plan = 'starter'): static
    {
        return $this->afterCreating(function (Team $team) use ($plan) {
            subscribeTeam($team, $plan);
        });
    }

    /**
     * Alias for subscribed('starter').
     */
    public function starterPlan(): static
    {
        return $this->subscribed('starter');
    }

    /**
     * Alias for subscribed('pro').
     */
    public function proPlan(): static
    {
        return $this->subscribed('pro');
    }
}
