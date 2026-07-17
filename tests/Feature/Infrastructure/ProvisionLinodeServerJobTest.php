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

it('records the Cloud Firewall id so teardown can release it', function () {
    $team = Team::factory()->create(['cloud_provider' => CloudProvider::Linode]);
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'cloud_provider' => CloudProvider::Linode,
    ]);

    $linode = Mockery::mock(LinodeService::class);
    $linode->shouldReceive('createVolume')->once()
        ->andReturn(['id' => 9001, 'filesystem_path' => '/dev/disk/by-id/vol']);
    $linode->shouldReceive('createInstance')->once()
        ->andReturn(['id' => 55501, '_root_password' => 'pw']);
    $linode->shouldReceive('extractIpAddress')->once()->andReturn('1.2.3.4');
    $linode->shouldReceive('attachVolume')->once();
    // The firewall id from createFirewall must be persisted to the server.
    $linode->shouldReceive('createFirewall')->once()->andReturn(['id' => 4242]);

    $factory = Mockery::mock(CloudServiceFactory::class);
    $factory->shouldReceive('make')->andReturn($linode);
    app()->instance(CloudServiceFactory::class, $factory);

    $scriptBuilder = Mockery::mock(CloudInitScriptBuilder::class);
    $scriptBuilder->shouldReceive('build')->andReturn('#!/bin/bash');
    app()->instance(CloudInitScriptBuilder::class, $scriptBuilder);

    (new ProvisionLinodeServerJob($server))->handle($factory, $scriptBuilder);

    expect($server->fresh()->provider_firewall_id)->toBe('4242');
});
