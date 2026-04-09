<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\Task;
use App\Models\TaskWorkProduct;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaskWorkProduct>
 */
class TaskWorkProductFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'task_id' => Task::factory(),
            'agent_id' => Agent::factory(),
            'type' => 'file',
            'title' => fake()->sentence(3),
            'file_path' => '/workspace/output/'.fake()->slug().'.md',
            'summary' => fake()->paragraph(),
        ];
    }
}
