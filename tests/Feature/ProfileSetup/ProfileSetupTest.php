<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('can view profile setup page', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('profile-setup'));

    $response->assertOk();
});

test('redirected to teams.create if profile already completed', function () {
    $user = User::factory()->withCompletedProfile()->create();

    $response = $this->actingAs($user)->get(route('profile-setup'));

    $response->assertRedirect(route('teams.create'));
});

test('stores all fields and sets profile_completed_at', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('profile-setup.store'), [
        'timezone' => 'America/New_York',
        'pronouns' => 'they/them',
    ]);

    $response->assertRedirect(route('teams.create'));

    $user->refresh();
    expect($user->timezone)->toBe('America/New_York')
        ->and($user->pronouns)->toBe('they/them')
        ->and($user->profile_completed_at)->not->toBeNull();
});

test('validates timezone is required', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('profile-setup.store'), [
        'timezone' => '',
    ]);

    $response->assertSessionHasErrors(['timezone']);
});

test('redirects to teams.create after store', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('profile-setup.store'), [
        'timezone' => 'UTC',
    ]);

    $response->assertRedirect(route('teams.create'));
});

test('stores profile with only required fields', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('profile-setup.store'), [
        'timezone' => 'Europe/London',
    ]);

    $response->assertRedirect(route('teams.create'));

    $user->refresh();
    expect($user->timezone)->toBe('Europe/London')
        ->and($user->profile_completed_at)->not->toBeNull();
});
