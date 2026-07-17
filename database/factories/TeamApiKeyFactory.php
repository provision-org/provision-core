<?php

namespace Database\Factories;

use App\Enums\LlmProvider;
use App\Models\Team;
use App\Models\TeamApiKey;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TeamApiKey>
 */
class TeamApiKeyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            // Bedrock is excluded: it authenticates via the EC2 instance
            // profile and never has an API key row.
            'provider' => fake()->randomElement(array_values(array_filter(
                LlmProvider::cases(),
                fn (LlmProvider $provider): bool => $provider !== LlmProvider::Bedrock,
            ))),
            'api_key' => 'sk-'.fake()->sha256(),
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the API key is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * A BYO-AWS cloud key: encrypted JSON credential payload.
     */
    public function awsCloud(): static
    {
        return $this->state(fn (array $attributes) => [
            'provider_type' => 'cloud',
            'provider' => 'aws',
            'api_key' => json_encode([
                'key_id' => 'AKIA'.fake()->regexify('[A-Z0-9]{16}'),
                'secret' => fake()->sha256(),
                'region' => 'us-east-1',
            ]),
        ]);
    }
}
