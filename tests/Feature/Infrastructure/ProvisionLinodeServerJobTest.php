<?php

use App\Enums\CloudProvider;
use App\Jobs\ProvisionLinodeServerJob;
use App\Models\Server;
use App\Models\Team;
use App\Services\CloudInitScriptBuilder;
use App\Services\CloudServiceFactory;
use App\Services\LinodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function linodeProvisionMocks(): array
{
    $linode = Mockery::mock(LinodeService::class);
    $linode->shouldReceive('createVolume')->once()
        ->andReturn(['id' => 9001, 'filesystem_path' => '/dev/disk/by-id/vol']);
    $linode->shouldReceive('createInstance')->once()
        ->andReturn(['id' => 55501, '_root_password' => 'pw']);
    $linode->shouldReceive('extractIpAddress')->once()->andReturn('1.2.3.4');
    $linode->shouldReceive('attachVolume')->once();

    $factory = Mockery::mock(CloudServiceFactory::class);
    $factory->shouldReceive('make')->andReturn($linode);
    app()->instance(CloudServiceFactory::class, $factory);

    $scriptBuilder = Mockery::mock(CloudInitScriptBuilder::class);
    $scriptBuilder->shouldReceive('build')->andReturn('#!/bin/bash');
    app()->instance(CloudInitScriptBuilder::class, $scriptBuilder);

    return [$linode, $factory, $scriptBuilder];
}

function linodeServer(): Server
{
    $team = Team::factory()->create(['cloud_provider' => CloudProvider::Linode]);

    return Server::factory()->create([
        'team_id' => $team->id,
        'cloud_provider' => CloudProvider::Linode,
    ]);
}

it('records the Cloud Firewall id so teardown can release it', function () {
    $server = linodeServer();
    [$linode, $factory, $scriptBuilder] = linodeProvisionMocks();
    $linode->shouldReceive('createFirewall')->once()->andReturn(['id' => 4242]);

    (new ProvisionLinodeServerJob($server))->handle($factory, $scriptBuilder);

    expect($server->fresh()->provider_firewall_id)->toBe('4242');
});

it('does not abort provisioning when firewall creation fails, and records why', function () {
    // The instance already exists and self-registers via its cloud-init
    // callback, so throwing here would leave a live, unprotected server whose
    // only trace of the failure is a missing provisioning_started event. That
    // is how an exhausted account-wide Linode firewall cap silently left later
    // servers with no firewall at all.
    $server = linodeServer();
    [$linode, $factory, $scriptBuilder] = linodeProvisionMocks();
    $linode->shouldReceive('createFirewall')->once()
        ->andThrow(new RuntimeException('Maximum number of firewalls reached'));

    (new ProvisionLinodeServerJob($server))->handle($factory, $scriptBuilder);

    $events = $server->fresh()->events()->pluck('event');

    expect($server->fresh()->provider_firewall_id)->toBeNull()
        // The failure is surfaced explicitly…
        ->and($events)->toContain('firewall_creation_failed')
        // …and provisioning still completes its normal bookkeeping.
        ->and($events)->toContain('provisioning_started');
});
