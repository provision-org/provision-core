<?php

use App\Contracts\CommandExecutor;
use App\Enums\ServerStatus;
use App\Jobs\SetupOpenClawOnServerJob;
use App\Jobs\UpdateEnvOnServerJob;
use App\Models\Server;
use App\Services\HarnessManager;
use App\Services\Scripts\ServerSetupScriptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake([UpdateEnvOnServerJob::class]);
});

function mockScriptService(): ServerSetupScriptService
{
    $service = Mockery::mock(ServerSetupScriptService::class);
    $service->shouldReceive('buildSignedUrl')->andReturn('https://provision-core.test/api/scripts/server-setup/signed-url');

    return $service;
}

function mockHarnessManagerForSetup(): HarnessManager
{
    $executor = Mockery::mock(CommandExecutor::class);
    $executor->shouldReceive('execScript')->andReturn('ok');

    $harnessManager = Mockery::mock(HarnessManager::class);
    $harnessManager->shouldReceive('resolveExecutor')->andReturn($executor);

    return $harnessManager;
}

it('runs setup script via signed URL over ssh', function () {
    $server = Server::factory()->running()->create();

    $executor = Mockery::mock(CommandExecutor::class);
    $executor->shouldReceive('execScript')
        ->once()
        ->with(Mockery::type('string'))
        ->andReturn('ok');

    $harnessManager = Mockery::mock(HarnessManager::class);
    $harnessManager->shouldReceive('resolveExecutor')->once()->andReturn($executor);

    (new SetupOpenClawOnServerJob($server))->handle($harnessManager, mockScriptService());
});

it('uses ServerSetupScriptService to build the signed URL', function () {
    $server = Server::factory()->running()->create();

    $scriptService = Mockery::mock(ServerSetupScriptService::class);
    $scriptService->shouldReceive('buildSignedUrl')
        ->once()
        ->with(Mockery::on(fn ($s) => $s->id === $server->id))
        ->andReturn('https://provision-core.test/api/scripts/server-setup/test-signed');

    $executor = Mockery::mock(CommandExecutor::class);
    $executor->shouldReceive('execScript')
        ->once()
        ->with('https://provision-core.test/api/scripts/server-setup/test-signed')
        ->andReturn('ok');

    $harnessManager = Mockery::mock(HarnessManager::class);
    $harnessManager->shouldReceive('resolveExecutor')->andReturn($executor);

    (new SetupOpenClawOnServerJob($server))->handle($harnessManager, $scriptService);
});

it('updates server status to running', function () {
    $server = Server::factory()->running()->create();

    (new SetupOpenClawOnServerJob($server))->handle(mockHarnessManagerForSetup(), mockScriptService());

    $server->refresh();
    expect($server->status)->toBe(ServerStatus::Running)
        ->and($server->provisioned_at)->not->toBeNull();
});

it('marks server as error when setup script completes but callback never fired', function () {
    $server = Server::factory()->running()->create([
        'status' => ServerStatus::SetupComplete,
    ]);

    (new SetupOpenClawOnServerJob($server))->handle(mockHarnessManagerForSetup(), mockScriptService());

    $server->refresh();
    expect($server->status)->toBe(ServerStatus::Error)
        ->and($server->events()->where('event', 'provisioning_error')->exists())->toBeTrue();
});

it('dispatches UpdateEnvOnServerJob after setup', function () {
    $server = Server::factory()->running()->create();

    (new SetupOpenClawOnServerJob($server))->handle(mockHarnessManagerForSetup(), mockScriptService());

    Bus::assertDispatched(UpdateEnvOnServerJob::class, fn ($job) => $job->server->id === $server->id);
});
