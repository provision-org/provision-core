<?php

use App\Models\User;

test('admin can view teams index', function () {
    $admin = User::factory()->admin()->withPersonalTeam()->create();
    User::factory()->withPersonalTeam()->count(2)->create();

    $response = $this->actingAs($admin)->get(route('admin.teams.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('admin/teams/index')
        ->has('teams.data', 3) // admin's team + 2 user teams
    );
});
