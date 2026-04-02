<?php

use App\Jobs\ProvisionHetznerServerJob;
use App\Models\Server;
use App\Services\CloudInitScriptBuilder;
use App\Services\CloudServiceFactory;
use App\Services\HetznerService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function mockHetznerServiceWithVolume(string $volumeId = '55555555'): HetznerService
{
    $hetznerService = Mockery::mock(HetznerService::class);
    $hetznerService->shouldReceive('createVolume')
        ->once()
        ->andReturn(['volume' => ['id' => (int) $volumeId]]);

    return $hetznerService;
}

function mockCloudFactoryForHetzner(HetznerService $hetznerService): CloudServiceFactory
{
    $factory = Mockery::mock(CloudServiceFactory::class);
    $factory->shouldReceive('make')->andReturn($hetznerService);

    return $factory;
}

it('calls hetzner service to create a server with volume', function () {
    $server = Server::factory()->create();

    $hetznerService = mockHetznerServiceWithVolume();
    $hetznerService->shouldReceive('createServer')
        ->once()
        ->with($server->team, Mockery::type('string'), [55555555], Mockery::type('string'))
        ->andReturn([
            'server' => [
                'id' => 12345678,
                'public_net' => [
                    'ipv4' => ['ip' => '1.2.3.4'],
                ],
            ],
        ]);

    app()->instance(HetznerService::class, $hetznerService);

    (new ProvisionHetznerServerJob($server))->handle(mockCloudFactoryForHetzner($hetznerService), app(CloudInitScriptBuilder::class));

    $server->refresh();
    expect($server->provider_server_id)->toBe('12345678')
        ->and($server->ipv4_address)->toBe('1.2.3.4');
});

it('creates a provisioning_started event', function () {
    $server = Server::factory()->create();

    $hetznerService = mockHetznerServiceWithVolume();
    $hetznerService->shouldReceive('createServer')
        ->once()
        ->andReturn([
            'server' => [
                'id' => 99999,
                'public_net' => [
                    'ipv4' => ['ip' => '5.6.7.8'],
                ],
            ],
        ]);

    (new ProvisionHetznerServerJob($server))->handle(mockCloudFactoryForHetzner($hetznerService), app(CloudInitScriptBuilder::class));

    expect($server->events()->where('event', 'provisioning_started')->exists())->toBeTrue();
});

it('generates a callback url with hmac signature', function () {
    $server = Server::factory()->create();

    $hetznerService = mockHetznerServiceWithVolume();
    $hetznerService->shouldReceive('createServer')
        ->once()
        ->withArgs(function ($team, $cloudInit, $volumeIds) {
            return str_contains($cloudInit, 'server-ready')
                && str_contains($cloudInit, 'signature=')
                && $volumeIds === [55555555];
        })
        ->andReturn([
            'server' => [
                'id' => 11111,
                'public_net' => [
                    'ipv4' => ['ip' => '10.0.0.1'],
                ],
            ],
        ]);

    (new ProvisionHetznerServerJob($server))->handle(mockCloudFactoryForHetzner($hetznerService), app(CloudInitScriptBuilder::class));
});

it('persists provider_volume_id on the server record', function () {
    $server = Server::factory()->create();

    $hetznerService = mockHetznerServiceWithVolume('77777777');
    $hetznerService->shouldReceive('createServer')
        ->once()
        ->andReturn([
            'server' => [
                'id' => 12345678,
                'public_net' => [
                    'ipv4' => ['ip' => '1.2.3.4'],
                ],
            ],
        ]);

    (new ProvisionHetznerServerJob($server))->handle(mockCloudFactoryForHetzner($hetznerService), app(CloudInitScriptBuilder::class));

    $server->refresh();
    expect($server->provider_volume_id)->toBe('77777777');
});

it('includes volume mount and symlink instructions in cloud-init', function () {
    $server = Server::factory()->create();

    $hetznerService = mockHetznerServiceWithVolume('88888888');
    $hetznerService->shouldReceive('createServer')
        ->once()
        ->withArgs(function ($team, $cloudInit) {
            return str_contains($cloudInit, '/dev/disk/by-id/scsi-0HC_Volume_88888888')
                && str_contains($cloudInit, '/mnt/openclaw-data')
                && str_contains($cloudInit, 'ln -sfn /mnt/openclaw-data/agents /root/.openclaw/agents')
                && str_contains($cloudInit, 'ln -sfn /mnt/openclaw-data/logs /root/.openclaw/logs');
        })
        ->andReturn([
            'server' => [
                'id' => 11111,
                'public_net' => [
                    'ipv4' => ['ip' => '10.0.0.1'],
                ],
            ],
        ]);

    (new ProvisionHetznerServerJob($server))->handle(mockCloudFactoryForHetzner($hetznerService), app(CloudInitScriptBuilder::class));
});
