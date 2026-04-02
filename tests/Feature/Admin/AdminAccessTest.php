<?php

use App\Models\User;

test('guest is redirected to login from admin routes', function () {
    $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
});

test('non-admin user gets 403 on admin routes', function () {
    $user = User::factory()->withPersonalTeam()->create();

    $this->actingAs($user)->get(route('admin.dashboard'))->assertForbidden();
});

test('admin user can access admin dashboard', function () {
    $user = User::factory()->admin()->withPersonalTeam()->create();

    $this->actingAs($user)->get(route('admin.dashboard'))->assertOk();
});

test('non-admin user cannot access admin users page', function () {
    $user = User::factory()->withPersonalTeam()->create();

    $this->actingAs($user)->get(route('admin.users.index'))->assertForbidden();
});

test('non-admin user cannot activate a user', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $target = User::factory()->waitlisted()->create();

    $this->actingAs($user)
        ->post(route('admin.users.activate', $target))
        ->assertForbidden();
});
