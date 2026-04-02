<?php

use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

test('agents page redirects to provisioning when server is not running', function () {
    Bus::fake();
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    Server::factory()->create(['team_id' => $team->id, 'status' => 'provisioning']);

    $response = $this->actingAs($user)->get(route('agents.index'));

    $response->assertRedirect(route('teams.provisioning', $team));
});

test('agents page is accessible when server is running', function () {
    Bus::fake();
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    Server::factory()->running()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)->get(route('agents.index'));

    $response->assertSuccessful();
});

test('agents page is accessible when team has no server', function () {
    Bus::fake();
    $user = User::factory()->withPersonalTeam()->create();

    $response = $this->actingAs($user)->get(route('agents.index'));

    $response->assertSuccessful();
});

test('api keys page is accessible while server is provisioning', function () {
    Bus::fake();
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    Server::factory()->create(['team_id' => $team->id, 'status' => 'provisioning']);

    $response = $this->actingAs($user)->get(route('api-keys.index'));

    $response->assertSuccessful();
});
