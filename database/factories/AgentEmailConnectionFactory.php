<?php

namespace Database\Factories;

use App\Models\Agent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AgentEmailConnection>
 */
class AgentEmailConnectionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'agent_id' => Agent::factory(),
            'email_address' => fake()->userName().'@'.config('mailboxkit.email_domain', 'provisionagents.com'),
            'mailboxkit_inbox_id' => fake()->numberBetween(1, 99999),
            'status' => 'connected',
        ];
    }
}
