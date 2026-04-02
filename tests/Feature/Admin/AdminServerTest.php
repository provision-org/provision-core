<?php

use App\Models\Server;
use App\Models\User;

test('admin can view servers index', function () {
    $admin = User::factory()->admin()->withPersonalTeam()->create();
    $user = User::factory()->withPersonalTeam()->create();
    Server::factory()->for($user->currentTeam, 'team')->create();

    $response = $this->actingAs($admin)->get(route('admin.servers.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('admin/servers/index')
        ->has('servers.data', 1)
    );
});
