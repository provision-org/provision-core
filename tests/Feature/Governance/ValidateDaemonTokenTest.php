<?php

use App\Models\Server;
use App\Models\User;

test('valid daemon token passes middleware', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;

    Server::factory()->running()->create([
        'team_id' => $team->id,
        'daemon_token' => 'valid-token-abc',
    ]);

    $response = $this->postJson('/api/daemon/valid-token-abc/heartbeat');

    $response->assertOk();
});

test('invalid daemon token returns 401', function () {
    $response = $this->getJson('/api/daemon/bad-token/work-queue');

    $response->assertUnauthorized();
});

test('missing token returns 404', function () {
    $response = $this->getJson('/api/daemon//work-queue');

    // Empty token segment results in a 404 from the router
    $response->assertNotFound();
});
