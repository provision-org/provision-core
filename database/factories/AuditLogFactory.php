<?php

namespace Database\Factories;

use App\Enums\ActorType;
use App\Models\AuditLog;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'actor_type' => ActorType::System,
            'actor_id' => fake()->uuid(),
            'action' => 'task.created',
            'created_at' => now(),
        ];
    }

    public function byUser(string $userId): static
    {
        return $this->state(fn (array $attributes) => [
            'actor_type' => ActorType::User,
            'actor_id' => $userId,
        ]);
    }

    public function byAgent(string $agentId): static
    {
        return $this->state(fn (array $attributes) => [
            'actor_type' => ActorType::Agent,
            'actor_id' => $agentId,
        ]);
    }

    public function byDaemon(string $serverId): static
    {
        return $this->state(fn (array $attributes) => [
            'actor_type' => ActorType::Daemon,
            'actor_id' => $serverId,
        ]);
    }
}
