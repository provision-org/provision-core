<?php

namespace Database\Factories;

use App\Enums\CloudProvider;
use App\Enums\ServerStatus;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Server>
 */
class ServerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'name' => fake()->domainWord().'-server',
            'cloud_provider' => CloudProvider::Hetzner,
            'status' => ServerStatus::Provisioning,
            'server_type' => 'cx32',
            'region' => 'nbg1',
            'image' => 'ubuntu-24.04',
        ];
    }

    public function provisioning(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ServerStatus::Provisioning,
        ]);
    }

    public function running(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ServerStatus::Running,
            'provider_server_id' => (string) fake()->unique()->randomNumber(8),
            'provider_volume_id' => (string) fake()->unique()->randomNumber(8),
            'ipv4_address' => fake()->ipv4(),
            'vnc_password' => Str::random(16),
            'provisioned_at' => now()->subHour(),
            'openclaw_version' => 'v2026.3.8',
            'last_health_check' => now()->subMinutes(5),
        ]);
    }

    public function withVolume(): static
    {
        return $this->state(fn (array $attributes) => [
            'provider_volume_id' => (string) fake()->unique()->randomNumber(8),
        ]);
    }

    public function digitalOcean(): static
    {
        return $this->state(fn (array $attributes) => [
            'cloud_provider' => CloudProvider::DigitalOcean,
        ]);
    }

    public function error(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ServerStatus::Error,
        ]);
    }
}
