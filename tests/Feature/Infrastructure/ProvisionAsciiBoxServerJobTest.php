<?php

use App\Enums\CloudProvider;
use App\Enums\HarnessType;
use App\Enums\ServerStatus;
use App\Jobs\ProvisionAsciiBoxServerJob;
use App\Models\Server;
use App\Models\Team;
use App\Services\AsciiBoxService;
use App\Services\CloudInitScriptBuilder;
use App\Services\CloudServiceFactory;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('cloud.ascii.ssh_public_key_path', base_path('tests/Fixtures/ssh/provision_test.pub'));
});

function asciiProvisionServer(): Server
{
    $team = Team::factory()->ascii()->create([
        'harness_type' => HarnessType::OpenClaw,
    ]);

    return Server::factory()->ascii()->create([
        'team_id' => $team->id,
    ]);
}

function asciiProvisionFactory(AsciiBoxService $ascii): CloudServiceFactory
{
    $factory = Mockery::mock(CloudServiceFactory::class);
    $factory->shouldReceive('make')->andReturn($ascii);

    return $factory;
}

it('creates a box, configures ssh, and launches the bootstrap', function () {
    $server = asciiProvisionServer();

    $ascii = Mockery::mock(AsciiBoxService::class);
    $ascii->shouldReceive('createBox')
        ->once()
        ->with([
            'PROVISION_SERVER_ID' => $server->id,
            'PROVISION_TEAM_ID' => $server->team_id,
        ])
        ->andReturn(['id' => 'bx_23456789', 'state' => 'provisioning']);
    $ascii->shouldReceive('waitUntilReady')
        ->once()
        ->with('bx_23456789')
        ->andReturn(['id' => 'bx_23456789', 'state' => 'idle', 'ip' => '203.0.113.10']);
    $ascii->shouldReceive('extractIpAddress')->once()->andReturn('203.0.113.10');
    $ascii->shouldReceive('configureProvisionSsh')
        ->once()
        ->with('bx_23456789', Mockery::on(fn (string $key): bool => str_starts_with($key, 'ssh-ed25519 ')));
    $ascii->shouldReceive('startBootstrap')
        ->once()
        ->with('bx_23456789', '#!/bin/bash');

    $scriptBuilder = Mockery::mock(CloudInitScriptBuilder::class);
    $scriptBuilder->shouldReceive('buildForRootFilesystem')
        ->once()
        ->with(
            Mockery::on(fn (string $url): bool => str_contains($url, '/api/webhooks/server-ready')),
            $server->team->timezone,
            HarnessType::OpenClaw,
        )
        ->andReturn('#!/bin/bash');

    (new ProvisionAsciiBoxServerJob($server))->handle(asciiProvisionFactory($ascii), $scriptBuilder);

    $server->refresh();
    $event = $server->events()->where('event', 'provisioning_started')->first();

    expect($server->cloud_provider)->toBe(CloudProvider::Ascii)
        ->and($server->provider_server_id)->toBe('bx_23456789')
        ->and($server->ipv4_address)->toBe('203.0.113.10')
        ->and($server->provider_volume_id)->toBeNull()
        ->and($server->provider_firewall_id)->toBeNull()
        ->and($server->daemon_token)->not->toBeNull()
        ->and($event?->payload['provider'])->toBe('ascii');
});

it('reuses an existing box on a retry', function () {
    $server = asciiProvisionServer();
    $server->update(['provider_server_id' => 'bx_23456789']);

    $ascii = Mockery::mock(AsciiBoxService::class);
    $ascii->shouldNotReceive('createBox');
    $ascii->shouldReceive('waitUntilReady')
        ->once()
        ->with('bx_23456789')
        ->andReturn(['id' => 'bx_23456789', 'state' => 'idle', 'ip' => '203.0.113.10']);
    $ascii->shouldReceive('extractIpAddress')->once()->andReturn('203.0.113.10');
    $ascii->shouldReceive('configureProvisionSsh')->once();
    $ascii->shouldReceive('startBootstrap')->once();

    $scriptBuilder = Mockery::mock(CloudInitScriptBuilder::class);
    $scriptBuilder->shouldReceive('buildForRootFilesystem')->once()->andReturn('#!/bin/bash');

    (new ProvisionAsciiBoxServerJob($server))->handle(asciiProvisionFactory($ascii), $scriptBuilder);

    expect($server->fresh()->provider_server_id)->toBe('bx_23456789')
        ->and($server->events()->where('event', 'provisioning_started')->count())->toBe(1);
});

it('deduplicates concurrent provisioning jobs for the same server', function () {
    $server = asciiProvisionServer();
    $job = new ProvisionAsciiBoxServerJob($server);

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe((string) $server->id);
});

it('archives a box when provisioning ultimately fails', function () {
    $server = asciiProvisionServer();
    $server->update(['provider_server_id' => 'bx_23456789']);

    $ascii = Mockery::mock(AsciiBoxService::class);
    $ascii->shouldReceive('archiveBox')->once()->with('bx_23456789');

    $factory = Mockery::mock(CloudServiceFactory::class);
    $factory->shouldReceive('make')->once()->andReturn($ascii);
    app()->instance(CloudServiceFactory::class, $factory);

    (new ProvisionAsciiBoxServerJob($server))->failed(new RuntimeException('bootstrap failed'));

    expect($server->fresh()->status)->toBe(ServerStatus::Error)
        ->and($server->events()->where('event', 'provisioning_error')->exists())->toBeTrue();
});
