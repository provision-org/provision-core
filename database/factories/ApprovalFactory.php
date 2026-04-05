<?php

namespace Database\Factories;

use App\Enums\ApprovalStatus;
use App\Enums\ApprovalType;
use App\Models\Agent;
use App\Models\Approval;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Approval>
 */
class ApprovalFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'requesting_agent_id' => Agent::factory(),
            'type' => ApprovalType::ExternalAction,
            'status' => ApprovalStatus::Pending,
            'title' => fake()->sentence(3),
            'payload' => ['detail' => fake()->sentence()],
        ];
    }

    public function hireAgent(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => ApprovalType::HireAgent,
            'expires_at' => now()->addHours(72),
        ]);
    }

    public function externalAction(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => ApprovalType::ExternalAction,
            'expires_at' => now()->addHours(4),
        ]);
    }

    public function strategyProposal(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => ApprovalType::StrategyProposal,
            'expires_at' => now()->addHours(72),
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ApprovalStatus::Approved,
            'reviewed_at' => now(),
            'review_note' => 'Approved.',
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ApprovalStatus::Rejected,
            'reviewed_at' => now(),
            'review_note' => 'Rejected.',
        ]);
    }
}
