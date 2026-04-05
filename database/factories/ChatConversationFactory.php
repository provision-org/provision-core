<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\ChatConversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ChatConversation>
 */
class ChatConversationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $ulid = strtolower(Str::ulid());

        return [
            'agent_id' => Agent::factory(),
            'user_id' => User::factory(),
            'title' => fake()->sentence(3),
            'session_key' => "web:{$ulid}",
            'last_message_at' => now(),
        ];
    }
}
