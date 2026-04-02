<?php

use App\Models\User;

test('admin dashboard renders with stats', function () {
    $admin = User::factory()->admin()->withPersonalTeam()->create();

    $response = $this->actingAs($admin)->get(route('admin.dashboard'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('admin/dashboard')
        ->has('stats.total_users')
        ->has('stats.activated_users')
        ->has('stats.waitlisted_users')
        ->has('stats.total_teams')
        ->has('stats.total_agents')
        ->has('stats.total_servers')
        ->has('recentSignups')
    );
});

test('admin dashboard shows correct user counts', function () {
    $admin = User::factory()->admin()->withPersonalTeam()->create();
    User::factory()->count(2)->create();
    User::factory()->waitlisted()->count(3)->create();

    $response = $this->actingAs($admin)->get(route('admin.dashboard'));

    $response->assertInertia(fn ($page) => $page
        ->where('stats.total_users', 6) // admin + 2 active + 3 waitlisted
        ->where('stats.activated_users', 3) // admin + 2 active
        ->where('stats.waitlisted_users', 3)
    );
});
