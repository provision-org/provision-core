<?php

use App\Enums\CloudProvider;
use App\Jobs\ProvisionDigitalOceanServerJob;
use App\Models\Server;
use App\Services\CloudInitScriptBuilder;
use App\Services\CloudServiceFactory;
use App\Services\DigitalOceanService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function mockDoServiceWithVolume(string $volumeId = '55555555'): DigitalOceanService
{
    $doService = Mockery::mock(DigitalOceanService::class);
    $doService->shouldReceive('createVolume')
        ->once()
        ->andReturn(['volume' => ['id' => $volumeId]]);

    return $doService;
}

function mockCloudFactoryForDo(DigitalOceanService $doService): CloudServiceFactory
{
    $factory = Mockery::mock(CloudServiceFactory::class);
    $factory->shouldReceive('make')->andReturn($doService);

    return $factory;
}

it('creates a droplet with volume on digital ocean', function () {
    $server = Server::factory()->digitalOcean()->create();

    $doService = mockDoServiceWithVolume('vol-123');
    $doService->shouldReceive('createDroplet')
        ->once()
        ->andReturn([
            'droplet' => [
                'id' => 98765432,
                'networks' => [
                    'v4' => [
                        ['type' => 'public', 'ip_address' => '104.131.1.1'],
                    ],
                ],
            ],
        ]);
    $doService->shouldReceive('extractIpAddress')
        ->once()
        ->andReturn('104.131.1.1');
    $doService->shouldReceive('createFirewall')
        ->once()
        ->andReturn(['firewall' => ['id' => 'fw-1']]);

    app()->instance(DigitalOceanService::class, $doService);

    (new ProvisionDigitalOceanServerJob($server))->handle(mockCloudFactoryForDo($doService), app(CloudInitScriptBuilder::class));

    $server->refresh();
    expect($server->provider_server_id)->toBe('98765432')
        ->and($server->provider_volume_id)->toBe('vol-123')
        ->and($server->ipv4_address)->toBe('104.131.1.1')
        ->and($server->cloud_provider)->toBe(CloudProvider::DigitalOcean);
});

it('creates a provisioning_started event with digitalocean provider', function () {
    $server = Server::factory()->digitalOcean()->create();

    $doService = mockDoServiceWithVolume();
    $doService->shouldReceive('createDroplet')
        ->once()
        ->andReturn([
            'droplet' => [
                'id' => 11111,
                'networks' => ['v4' => [['type' => 'public', 'ip_address' => '10.0.0.1']]],
            ],
        ]);
    $doService->shouldReceive('extractIpAddress')->andReturn('10.0.0.1');
    $doService->shouldReceive('createFirewall')->andReturn(['firewall' => ['id' => 'fw-1']]);

    (new ProvisionDigitalOceanServerJob($server))->handle(mockCloudFactoryForDo($doService), app(CloudInitScriptBuilder::class));

    $event = $server->events()->where('event', 'provisioning_started')->first();
    expect($event)->not->toBeNull()
        ->and($event->payload['provider'])->toBe('digitalocean');
});

it('uses DO volume device path in cloud-init', function () {
    $server = Server::factory()->digitalOcean()->create();

    $doService = mockDoServiceWithVolume();
    $doService->shouldReceive('createDroplet')
        ->once()
        ->withArgs(function ($team, $cloudInit) {
            return str_contains($cloudInit, '/dev/disk/by-id/scsi-0DO_Volume_');
        })
        ->andReturn([
            'droplet' => [
                'id' => 22222,
                'networks' => ['v4' => [['type' => 'public', 'ip_address' => '10.0.0.2']]],
            ],
        ]);
    $doService->shouldReceive('extractIpAddress')->andReturn('10.0.0.2');
    $doService->shouldReceive('createFirewall')->andReturn(['firewall' => ['id' => 'fw-1']]);

    (new ProvisionDigitalOceanServerJob($server))->handle(mockCloudFactoryForDo($doService), app(CloudInitScriptBuilder::class));
});

it('cleans up orphaned volume on failure', function () {
    $server = Server::factory()->digitalOcean()->create([
        'provider_volume_id' => 'vol-orphan',
        'provider_server_id' => null,
    ]);

    $doService = Mockery::mock(DigitalOceanService::class);
    $doService->shouldReceive('deleteVolume')
        ->once()
        ->with('vol-orphan');

    $factory = Mockery::mock(CloudServiceFactory::class);
    $factory->shouldReceive('make')->andReturn($doService);
    app()->instance(CloudServiceFactory::class, $factory);

    $job = new ProvisionDigitalOceanServerJob($server);
    $job->failed(new RuntimeException('Test failure'));
});
