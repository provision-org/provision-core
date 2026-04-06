<?php

use App\Contracts\Modules\BillingProvider;
use App\Models\User;

beforeEach(function () {
    if (! app()->bound(BillingProvider::class)) {
        $this->markTestSkipped('Waitlist requires the billing module');
    }
});

test('waitlisted user is redirected to waitlist from protected routes', function () {
    $user = User::factory()->waitlisted()->create();

    $response = $this->actingAs($user)->get(route('agents.index'));

    $response->assertRedirect(route('waitlist'));
});

test('activated user can access protected routes', function () {
    $user = User::factory()->withPersonalTeam()->create();

    $response = $this->actingAs($user)->get(route('agents.index'));

    $response->assertOk();
});

test('waitlist page can be rendered by waitlisted user', function () {
    $user = User::factory()->waitlisted()->create();

    $response = $this->actingAs($user)->get(route('waitlist'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->component('waitlist'));
});

test('activated user on waitlist page is redirected to dashboard', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('waitlist'));

    $response->assertRedirect(route('dashboard'));
});

test('new registration does not set activated_at', function () {
    $this->post(route('register.store'), [
        'name' => 'Waitlist User',
        'email' => 'waitlist@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $user = User::where('email', 'waitlist@example.com')->first();

    expect($user)->not->toBeNull();
    expect($user->activated_at)->toBeNull();
    expect($user->isActivated())->toBeFalse();
});

test('activate user command activates a waitlisted user', function () {
    $user = User::factory()->waitlisted()->create(['email' => 'pending@example.com']);

    $this->artisan('user:activate', ['email' => 'pending@example.com'])
        ->expectsOutput("User 'pending@example.com' has been activated.")
        ->assertSuccessful();

    $user->refresh();
    expect($user->isActivated())->toBeTrue();
});

test('activate user command reports already activated user', function () {
    User::factory()->create(['email' => 'active@example.com']);

    $this->artisan('user:activate', ['email' => 'active@example.com'])
        ->expectsOutput("User 'active@example.com' is already activated.")
        ->assertSuccessful();
});

test('activate user command fails for non-existent user', function () {
    $this->artisan('user:activate', ['email' => 'nobody@example.com'])
        ->expectsOutput("User with email 'nobody@example.com' not found.")
        ->assertFailed();
});

test('admin user bypasses waitlist even without activation', function () {
    $user = User::factory()->waitlisted()->admin()->withPersonalTeam()->create();

    $response = $this->actingAs($user)->get(route('agents.index'));

    $response->assertOk();
});

test('waitlisted user can still access profile and password settings', function () {
    $user = User::factory()->waitlisted()->create();

    $response = $this->actingAs($user)->get(route('profile.edit'));
    $response->assertOk();
});

test('waitlisted user cannot access team settings', function () {
    $user = User::factory()->waitlisted()->withPersonalTeam()->create();

    $response = $this->actingAs($user)->get(route('teams.show', $user->currentTeam));

    $response->assertRedirect(route('waitlist'));
});
