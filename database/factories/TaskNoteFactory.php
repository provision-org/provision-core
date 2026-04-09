<?php

namespace Database\Factories;

use App\Models\Task;
use App\Models\TaskNote;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaskNote>
 */
class TaskNoteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'task_id' => Task::factory(),
            'author_type' => 'user',
            'author_id' => fake()->uuid(),
            'body' => fake()->paragraph(),
        ];
    }

    public function byAgent(): static
    {
        return $this->state(fn (array $attributes) => [
            'author_type' => 'agent',
        ]);
    }
}
