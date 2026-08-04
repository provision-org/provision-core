<?php

use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\Route;

test('legacy path daemon token remains compatible', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;

    Server::factory()->running()->create([
        'team_id' => $team->id,
        'daemon_token' => 'valid-token-abc',
    ]);

    $response = $this->postJson('/api/daemon/valid-token-abc/heartbeat');

    $response->assertOk();
});

test('invalid legacy path daemon token returns 401', function () {
    $response = $this->getJson('/api/daemon/bad-token/work-queue');

    $response->assertUnauthorized();
});

test('missing token returns 404', function () {
    $response = $this->getJson('/api/daemon//work-queue');

    // Empty token segment results in a 404 from the router
    $response->assertNotFound();
});

test('server-scoped bearer token authenticates the exact server', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $server = Server::factory()->running()->create([
        'team_id' => $user->currentTeam->id,
        'daemon_token' => 'header-token-abc',
        'last_health_check' => null,
    ]);

    $this->withToken('header-token-abc')
        ->postJson("/api/daemon/servers/{$server->id}/heartbeat")
        ->assertSuccessful();

    expect($server->fresh()->last_health_check)->not->toBeNull();
});

test('server-scoped daemon route rejects missing and invalid bearer tokens', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $server = Server::factory()->running()->create([
        'team_id' => $user->currentTeam->id,
        'daemon_token' => 'correct-header-token',
    ]);

    $this->postJson("/api/daemon/servers/{$server->id}/heartbeat")
        ->assertUnauthorized();
    $this->withToken('wrong-header-token')
        ->postJson("/api/daemon/servers/{$server->id}/heartbeat")
        ->assertUnauthorized();
});

test('a valid token for another server cannot authenticate the requested server', function () {
    $firstUser = User::factory()->withPersonalTeam()->create();
    $firstServer = Server::factory()->running()->create([
        'team_id' => $firstUser->currentTeam->id,
        'daemon_token' => 'first-server-token',
    ]);
    $secondUser = User::factory()->withPersonalTeam()->create();
    $secondServer = Server::factory()->running()->create([
        'team_id' => $secondUser->currentTeam->id,
        'daemon_token' => 'second-server-token',
    ]);

    $this->withToken('second-server-token')
        ->postJson("/api/daemon/servers/{$firstServer->id}/heartbeat")
        ->assertUnauthorized();

    expect($firstServer->fresh()->id)->not->toBe($secondServer->id);
});

test('server-scoped daemon route does not fall back to another server for an unknown id', function () {
    $user = User::factory()->withPersonalTeam()->create();
    Server::factory()->running()->create([
        'team_id' => $user->currentTeam->id,
        'daemon_token' => 'otherwise-valid-token',
    ]);

    $this->withToken('otherwise-valid-token')
        ->postJson('/api/daemon/servers/01aaaaaaaaaaaaaaaaaaaaaaaa/heartbeat')
        ->assertUnauthorized();
});

test('the full daemon API surface is registered under bearer and legacy prefixes', function () {
    $routes = collect(Route::getRoutes())->map(fn ($route) => $route->uri())->all();
    $suffixes = [
        'work-queue',
        'tasks/{task}/checkout',
        'tasks/{task}/result',
        'tasks/{task}/release',
        'resolved-approvals',
        'usage-events',
        'tasks/{task}/notes',
        'chat/events',
        'chat/sessions/snapshot',
        'heartbeat',
    ];

    foreach ($suffixes as $suffix) {
        expect($routes)
            ->toContain("api/daemon/servers/{server}/{$suffix}")
            ->toContain("api/daemon/{token}/{$suffix}");
    }
});
