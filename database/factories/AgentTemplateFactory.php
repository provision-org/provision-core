<?php

namespace Database\Factories;

use App\Enums\AgentRole;
use App\Models\AgentTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentTemplate>
 */
class AgentTemplateFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'slug' => fake()->unique()->slug(2),
            'name' => fake()->firstName(),
            'tagline' => fake()->sentence(4),
            'emoji' => fake()->randomElement(['🧭', '🔧', '✍️', '✨', '🔍', '🎯', '💛', '📊', '⚡', '🌐']),
            'role' => AgentRole::Custom,
            'system_prompt' => fake()->paragraphs(2, true),
            'identity' => fake()->paragraphs(2, true),
            'soul' => fake()->paragraphs(3, true),
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    public function projectManager(): static
    {
        return $this->state(fn (array $attributes) => [
            'slug' => 'atlas',
            'name' => 'Atlas',
            'role' => AgentRole::ProjectManager,
            'emoji' => '🧭',
            'tagline' => 'Strategic project orchestrator',
        ]);
    }

    public function backendDeveloper(): static
    {
        return $this->state(fn (array $attributes) => [
            'slug' => 'forge',
            'name' => 'Forge',
            'role' => AgentRole::BackendDeveloper,
            'emoji' => '🔧',
            'tagline' => 'Full-stack backend engineer',
        ]);
    }
}
