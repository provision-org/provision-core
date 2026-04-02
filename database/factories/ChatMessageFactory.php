<?php

namespace Database\Factories;

use App\Enums\ChatMessageRole;
use App\Models\ChatConversation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ChatMessage>
 */
class ChatMessageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'chat_conversation_id' => ChatConversation::factory(),
            'role' => ChatMessageRole::User,
            'content' => [['type' => 'text', 'text' => fake()->sentence()]],
            'sent_at' => now(),
        ];
    }

    public function assistant(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => ChatMessageRole::Assistant,
        ]);
    }
}
