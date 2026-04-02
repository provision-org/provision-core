<?php

use App\Models\User;

test('admin can view users index', function () {
    $admin = User::factory()->admin()->withPersonalTeam()->create();
    User::factory()->count(3)->create();

    $response = $this->actingAs($admin)->get(route('admin.users.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('admin/users/index')
        ->has('users.data', 4) // admin + 3 users
    );
});

test('admin can view user show page', function () {
    $admin = User::factory()->admin()->withPersonalTeam()->create();
    $user = User::factory()->withPersonalTeam()->create();

    $response = $this->actingAs($admin)->get(route('admin.users.show', $user));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('admin/users/show')
        ->where('user.id', $user->id)
        ->has('teams')
        ->has('agents')
    );
});

test('admin can activate a waitlisted user', function () {
    $admin = User::factory()->admin()->withPersonalTeam()->create();
    $user = User::factory()->waitlisted()->create();

    expect($user->isActivated())->toBeFalse();

    $this->actingAs($admin)
        ->post(route('admin.users.activate', $user))
        ->assertRedirect();

    $user->refresh();
    expect($user->isActivated())->toBeTrue();
});

test('admin can deactivate an active user', function () {
    $admin = User::factory()->admin()->withPersonalTeam()->create();
    $user = User::factory()->create();

    expect($user->isActivated())->toBeTrue();

    $this->actingAs($admin)
        ->post(route('admin.users.deactivate', $user))
        ->assertRedirect();

    $user->refresh();
    expect($user->isActivated())->toBeFalse();
});
