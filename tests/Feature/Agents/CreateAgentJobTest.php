<?php

use App\Contracts\CommandExecutor;
use App\Contracts\HarnessDriver;
use App\Enums\AgentStatus;
use App\Jobs\CreateAgentOnServerJob;
use App\Models\Agent;
use App\Models\Server;
use App\Models\Team;
use App\Services\HarnessManager;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('it runs install script via signed URL and activates agent', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->deploying()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'harness_agent_id' => 'agent-123',
    ]);

    $executor = Mockery::mock(CommandExecutor::class);

    $driver = Mockery::mock(HarnessDriver::class);
    $driver->shouldReceive('createAgent')
        ->once()
        ->with(Mockery::on(fn ($a) => $a->id === $agent->id), $executor)
        ->andReturnUsing(function (Agent $a) {
            $a->update(['status' => AgentStatus::Active]);
        });

    $harnessManager = Mockery::mock(HarnessManager::class);
    $harnessManager->shouldReceive('resolveExecutor')->once()->andReturn($executor);
    $harnessManager->shouldReceive('forAgent')->once()->andReturn($driver);

    (new CreateAgentOnServerJob($agent))->handle($harnessManager);

    expect($agent->fresh()->status)->toBe(AgentStatus::Active);
});

test('it activates agent on successful deployment', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->deploying()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'harness_agent_id' => 'agent-456',
    ]);

    $executor = Mockery::mock(CommandExecutor::class);

    $driver = Mockery::mock(HarnessDriver::class);
    $driver->shouldReceive('createAgent')
        ->once()
        ->andReturnUsing(function (Agent $a) {
            $a->update(['status' => AgentStatus::Active]);
        });

    $harnessManager = Mockery::mock(HarnessManager::class);
    $harnessManager->shouldReceive('resolveExecutor')->andReturn($executor);
    $harnessManager->shouldReceive('forAgent')->andReturn($driver);

    (new CreateAgentOnServerJob($agent))->handle($harnessManager);

    $agent->refresh();
    expect($agent->status)->toBe(AgentStatus::Active);
});

test('it activates agent when config verified but health check fails after retry', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->deploying()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'harness_agent_id' => 'agent-789',
    ]);

    $executor = Mockery::mock(CommandExecutor::class);

    // Driver still activates agent even when health check fails (config was verified)
    $driver = Mockery::mock(HarnessDriver::class);
    $driver->shouldReceive('createAgent')
        ->once()
        ->andReturnUsing(function (Agent $a) {
            $a->update(['status' => AgentStatus::Active]);
        });

    $harnessManager = Mockery::mock(HarnessManager::class);
    $harnessManager->shouldReceive('resolveExecutor')->andReturn($executor);
    $harnessManager->shouldReceive('forAgent')->andReturn($driver);

    (new CreateAgentOnServerJob($agent))->handle($harnessManager);

    expect($agent->fresh()->status)->toBe(AgentStatus::Active);
});

test('it marks agent as error when config verification fails', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->deploying()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'harness_agent_id' => 'agent-missing',
    ]);

    $executor = Mockery::mock(CommandExecutor::class);

    // Driver marks agent as error when config verification fails
    $driver = Mockery::mock(HarnessDriver::class);
    $driver->shouldReceive('createAgent')
        ->once()
        ->andReturnUsing(function (Agent $a) {
            $a->update(['status' => AgentStatus::Error]);
        });

    $harnessManager = Mockery::mock(HarnessManager::class);
    $harnessManager->shouldReceive('resolveExecutor')->andReturn($executor);
    $harnessManager->shouldReceive('forAgent')->andReturn($driver);

    (new CreateAgentOnServerJob($agent))->handle($harnessManager);

    expect($agent->fresh()->status)->toBe(AgentStatus::Error);
});

test('it sets agent to error on failure', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->deploying()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
    ]);

    (new CreateAgentOnServerJob($agent))->failed(new RuntimeException('SSH failed'));

    expect($agent->fresh()->status)->toBe(AgentStatus::Error);
});
