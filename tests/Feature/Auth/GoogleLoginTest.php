<?php

use App\Models\User;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

function mockGoogleUser(string $id = '123456', string $email = 'jane@example.com', string $name = 'Jane Doe'): void
{
    $socialiteUser = new SocialiteUser;
    $socialiteUser->map([
        'id' => $id,
        'name' => $name,
        'email' => $email,
    ]);

    $provider = Mockery::mock(Provider::class);
    $provider->shouldReceive('user')->andReturn($socialiteUser);

    Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
}

it('redirects to Google', function () {
    $response = $this->get(route('auth.google'));

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('accounts.google.com');
});

it('creates a new user from Google callback', function () {
    mockGoogleUser();

    $response = $this->get(route('auth.google.callback'));

    $response->assertRedirect(route('dashboard'));
    $this->assertAuthenticated();

    $user = User::where('email', 'jane@example.com')->first();
    expect($user)->not->toBeNull()
        ->and($user->google_id)->toBe('123456')
        ->and($user->name)->toBe('Jane Doe')
        ->and($user->email_verified_at)->not->toBeNull();
});

it('links Google to existing password user', function () {
    $existing = User::factory()->create([
        'email' => 'jane@example.com',
        'google_id' => null,
    ]);

    mockGoogleUser();

    $response = $this->get(route('auth.google.callback'));

    $response->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($existing);

    expect($existing->fresh()->google_id)->toBe('123456');
});

it('logs in existing Google user', function () {
    $existing = User::factory()->create([
        'email' => 'jane@example.com',
        'google_id' => '123456',
    ]);

    mockGoogleUser();

    $response = $this->get(route('auth.google.callback'));

    $response->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($existing);
});
