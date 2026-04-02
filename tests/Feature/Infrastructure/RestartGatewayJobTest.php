<?php

use App\Contracts\CommandExecutor;
use App\Contracts\HarnessDriver;
use App\Enums\HarnessType;
use App\Jobs\RestartGatewayJob;
use App\Models\Agent;
use App\Models\Server;
use App\Services\HarnessManager;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('restarts the gateway service via ssh', function () {
    $server = Server::factory()->running()->create();
    Agent::factory()->create([
        'team_id' => $server->team_id,
        'server_id' => $server->id,
        'harness_agent_id' => 'test-agent',
    ]);

    $executor = Mockery::mock(CommandExecutor::class);
    $executor->shouldReceive('exec')->andReturn('ok');

    $driver = Mockery::mock(HarnessDriver::class);
    $driver->shouldReceive('restartGateway')->once();

    $harnessManager = Mockery::mock(HarnessManager::class);
    $harnessManager->shouldReceive('resolveExecutor')->once()->andReturn($executor);
    $harnessManager->shouldReceive('driver')->with(HarnessType::OpenClaw)->andReturn($driver);
    $harnessManager->shouldReceive('driver')->with(HarnessType::Hermes)->andReturn($driver);

    (new RestartGatewayJob($server))->handle($harnessManager);
});

it('runs a health check after restart', function () {
    $server = Server::factory()->running()->create();
    Agent::factory()->create([
        'team_id' => $server->team_id,
        'server_id' => $server->id,
        'harness_agent_id' => 'test-agent',
    ]);

    $executor = Mockery::mock(CommandExecutor::class);
    $executor->shouldReceive('exec')->andReturn('ok');

    $driver = Mockery::mock(HarnessDriver::class);
    $driver->shouldReceive('restartGateway')->once();

    $harnessManager = Mockery::mock(HarnessManager::class);
    $harnessManager->shouldReceive('resolveExecutor')->andReturn($executor);
    $harnessManager->shouldReceive('driver')->with(HarnessType::OpenClaw)->andReturn($driver);
    $harnessManager->shouldReceive('driver')->with(HarnessType::Hermes)->andReturn($driver);

    (new RestartGatewayJob($server))->handle($harnessManager);

    expect($server->events()->where('event', 'gateway_restarted')->exists())->toBeTrue();
});

it('logs event even when health check fails', function () {
    $server = Server::factory()->running()->create();

    $executor = Mockery::mock(CommandExecutor::class);
    $executor->shouldReceive('exec')->andReturn('ok');

    $harnessManager = Mockery::mock(HarnessManager::class);
    $harnessManager->shouldReceive('resolveExecutor')->andReturn($executor);

    (new RestartGatewayJob($server))->handle($harnessManager);

    expect($server->events()->where('event', 'gateway_restarted')->exists())->toBeTrue();
});
