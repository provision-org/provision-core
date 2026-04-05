<?php

use App\Enums\AgentStatus;
use App\Models\Agent;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\SshService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('it returns logs for an active agent on a running server', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'status' => AgentStatus::Active,
    ]);

    $mock = Mockery::mock(SshService::class);
    $mock->shouldReceive('connect')->once()->with(Mockery::on(fn ($s) => $s->id === $server->id))->andReturnSelf();
    $mock->shouldReceive('exec')->once()->with('openclaw logs 2>&1 | tail -200')->andReturn("[2026-03-04] Agent started\n[2026-03-04] Ready");
    $mock->shouldReceive('disconnect')->once();
    $this->app->instance(SshService::class, $mock);

    $response = $this->actingAs($user)->getJson(route('agents.logs', $agent));

    $response->assertOk()
        ->assertJsonStructure(['logs'])
        ->assertJson(['logs' => "[2026-03-04] Agent started\n[2026-03-04] Ready"]);
});

test('it returns 404 for an agent on a different team', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $foreignTeam = Team::factory()->create();
    $server = Server::factory()->running()->create(['team_id' => $foreignTeam->id]);
    $agent = Agent::factory()->create([
        'team_id' => $foreignTeam->id,
        'server_id' => $server->id,
        'status' => AgentStatus::Active,
    ]);

    $response = $this->actingAs($user)->getJson(route('agents.logs', $agent));

    $response->assertNotFound();
});

test('it returns error when agent has no server', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $agent = Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => null,
        'status' => AgentStatus::Active,
    ]);

    $response = $this->actingAs($user)->getJson(route('agents.logs', $agent));

    $response->assertStatus(422)
        ->assertJson(['error' => 'Agent is not active or has no server.']);
});

test('it returns error when agent is not active', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'status' => AgentStatus::Pending,
    ]);

    $response = $this->actingAs($user)->getJson(route('agents.logs', $agent));

    $response->assertStatus(422)
        ->assertJson(['error' => 'Agent is not active or has no server.']);
});

test('it returns 500 when SSH fails', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'status' => AgentStatus::Active,
    ]);

    $mock = Mockery::mock(SshService::class);
    $mock->shouldReceive('connect')->once()->andThrow(new RuntimeException('Connection refused'));
    $mock->shouldReceive('disconnect')->once();
    $this->app->instance(SshService::class, $mock);

    $response = $this->actingAs($user)->getJson(route('agents.logs', $agent));

    $response->assertStatus(500)
        ->assertJson(['error' => 'Failed to fetch logs from server.']);
});
