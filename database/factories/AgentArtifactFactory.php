<?php

namespace Database\Factories;

use App\Enums\ArtifactType;
use App\Enums\ArtifactVisibility;
use App\Models\Agent;
use App\Models\AgentArtifact;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AgentArtifact>
 */
class AgentArtifactFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->words(2, true);

        return [
            'agent_id' => Agent::factory(),
            'team_id' => Team::factory(),
            'name' => ucfirst($name),
            'path_slug' => Str::slug($name),
            'type' => ArtifactType::Static,
            'source_dir' => Str::slug($name),
            'visibility' => ArtifactVisibility::Public,
            'status' => 'live',
        ];
    }

    public function live(): static
    {
        return $this->state(fn () => ['status' => 'live']);
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => 'pending']);
    }
}
