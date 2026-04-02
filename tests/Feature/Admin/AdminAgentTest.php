<?php

use App\Models\Agent;
use App\Models\User;

test('admin can view agents index', function () {
    $admin = User::factory()->admin()->withPersonalTeam()->create();
    $user = User::factory()->withPersonalTeam()->create();
    Agent::factory()->for($user->currentTeam, 'team')->count(2)->create();

    $response = $this->actingAs($admin)->get(route('admin.agents.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('admin/agents/index')
        ->has('agents.data', 2)
    );
});
