<?php

use App\Enums\ServerStatus;
use App\Jobs\CheckServerHealthJob;
use App\Models\Server;
use App\Services\SshService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('only checks running servers', function () {
    $runningServer = Server::factory()->running()->create();
    Server::factory()->create(['status' => ServerStatus::Provisioning]);
    Server::factory()->create(['status' => ServerStatus::Stopped]);

    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')
        ->once()
        ->with(Mockery::on(fn ($s) => $s->id === $runningServer->id))
        ->andReturnSelf();
    $sshService->shouldReceive('execWithRetry')
        ->with('openclaw health')
        ->once()
        ->andReturn('healthy');
    $sshService->shouldReceive('execWithRetry')
        ->with('openclaw status --all')
        ->once()
        ->andReturn('all ok');
    $sshService->shouldReceive('disconnect')->once();

    (new CheckServerHealthJob)->handle($sshService);
});

it('updates last_health_check timestamp on success', function () {
    $server = Server::factory()->running()->create([
        'last_health_check' => now()->subHour(),
    ]);

    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')->andReturnSelf();
    $sshService->shouldReceive('execWithRetry')->andReturn('ok');
    $sshService->shouldReceive('disconnect');

    (new CheckServerHealthJob)->handle($sshService);

    $server->refresh();
    expect($server->last_health_check->isAfter(now()->subMinute()))->toBeTrue();
});

it('creates health_check_passed event on success', function () {
    $server = Server::factory()->running()->create();

    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')->andReturnSelf();
    $sshService->shouldReceive('execWithRetry')
        ->with('openclaw health')
        ->andReturn('healthy');
    $sshService->shouldReceive('execWithRetry')
        ->with('openclaw status --all')
        ->andReturn('all running');
    $sshService->shouldReceive('disconnect');

    (new CheckServerHealthJob)->handle($sshService);

    expect($server->events()->where('event', 'health_check_passed')->exists())->toBeTrue();
});

it('creates health_check_failed event on failure', function () {
    $server = Server::factory()->running()->create();

    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')
        ->andThrow(new RuntimeException('Connection failed'));
    $sshService->shouldReceive('disconnect');

    (new CheckServerHealthJob)->handle($sshService);

    expect($server->events()->where('event', 'health_check_failed')->exists())->toBeTrue();
});
