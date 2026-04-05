<?php

namespace Database\Factories;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'activated_at' => now(),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the model has two-factor authentication configured.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    /**
     * Indicate that the user is on the waitlist (not yet activated).
     */
    public function waitlisted(): static
    {
        return $this->state(fn (array $attributes) => [
            'activated_at' => null,
        ]);
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_admin' => true,
        ]);
    }

    /**
     * Indicate that the user has completed their profile.
     */
    public function withCompletedProfile(): static
    {
        return $this->state(fn (array $attributes) => [
            'pronouns' => fake()->randomElement(['he/him', 'she/her', 'they/them']),
            'timezone' => fake()->timezone(),
            'profile_completed_at' => now(),
        ]);
    }

    /**
     * Create a personal team for the user after creation.
     */
    public function withPersonalTeam(): static
    {
        return $this->state(fn (array $attributes) => [
            'profile_completed_at' => $attributes['profile_completed_at'] ?? now(),
        ])->afterCreating(function ($user) {
            $team = Team::forceCreate([
                'user_id' => $user->id,
                'name' => $user->name."'s Team",
                'personal_team' => true,
                'timezone' => $user->timezone ?? 'UTC',
            ]);

            $team->members()->attach($user, ['role' => TeamRole::Admin->value]);

            $user->update(['current_team_id' => $team->id]);
        });
    }
}
