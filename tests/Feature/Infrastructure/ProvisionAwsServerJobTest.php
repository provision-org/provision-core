<?php

use App\Enums\CloudProvider;
use App\Jobs\ProvisionAwsServerJob;
use App\Models\Server;
use App\Services\AwsService;
use App\Services\CloudInitScriptBuilder;
use App\Services\CloudServiceFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function mockCloudFactoryForAws(AwsService $awsService): CloudServiceFactory
{
    $factory = Mockery::mock(CloudServiceFactory::class);
    $factory->shouldReceive('make')->andReturn($awsService);

    return $factory;
}

it('launches an ec2 instance and persists provider ids', function () {
    $server = Server::factory()->aws()->create();

    $awsService = Mockery::mock(AwsService::class);
    $awsService->shouldReceive('createInstance')
        ->once()
        ->andReturn([
            'InstanceId' => 'i-0abc123def456',
            'PublicIpAddress' => '52.10.20.30',
        ]);
    $awsService->shouldReceive('extractIpAddress')
        ->once()
        ->andReturn('52.10.20.30');
    $awsService->shouldReceive('createSecurityGroup')
        ->once()
        ->with("provision-{$server->id}", 'i-0abc123def456')
        ->andReturn(['id' => 'sg-0fedcba987']);

    (new ProvisionAwsServerJob($server))->handle(mockCloudFactoryForAws($awsService), app(CloudInitScriptBuilder::class));

    $server->refresh();
    expect($server->provider_server_id)->toBe('i-0abc123def456')
        ->and($server->ipv4_address)->toBe('52.10.20.30')
        ->and($server->provider_firewall_id)->toBe('sg-0fedcba987')
        ->and($server->provider_volume_id)->toBeNull()
        ->and($server->daemon_token)->not->toBeNull()
        ->and($server->cloud_provider)->toBe(CloudProvider::Aws);
});

it('tolerates a missing public ip at launch time', function () {
    $server = Server::factory()->aws()->create();

    $awsService = Mockery::mock(AwsService::class);
    $awsService->shouldReceive('createInstance')
        ->once()
        ->andReturn(['InstanceId' => 'i-0pending']);
    $awsService->shouldReceive('extractIpAddress')
        ->once()
        ->andReturn(null);
    $awsService->shouldReceive('createSecurityGroup')
        ->once()
        ->andReturn(['id' => 'sg-1']);

    (new ProvisionAwsServerJob($server))->handle(mockCloudFactoryForAws($awsService), app(CloudInitScriptBuilder::class));

    $server->refresh();
    expect($server->provider_server_id)->toBe('i-0pending')
        ->and($server->ipv4_address)->toBeNull();
});

it('does not relaunch when a prior attempt already created the instance', function () {
    // Simulates a retry after createSecurityGroup failed on the eventual-
    // consistency race: provider_server_id is already set, so the retry must
    // resume at the security group step, never launching a second (orphaned) box.
    $server = Server::factory()->aws()->create([
        'provider_server_id' => 'i-0already',
        'provider_firewall_id' => null,
    ]);

    $awsService = Mockery::mock(AwsService::class);
    $awsService->shouldNotReceive('createInstance');
    $awsService->shouldNotReceive('extractIpAddress');
    $awsService->shouldReceive('createSecurityGroup')
        ->once()
        ->with("provision-{$server->id}", 'i-0already')
        ->andReturn(['id' => 'sg-0resumed']);

    (new ProvisionAwsServerJob($server))->handle(mockCloudFactoryForAws($awsService), app(CloudInitScriptBuilder::class));

    $server->refresh();
    expect($server->provider_server_id)->toBe('i-0already')
        ->and($server->provider_firewall_id)->toBe('sg-0resumed');

    $event = $server->events()->where('event', 'provisioning_started')->first();
    expect($event->payload['provider_server_id'])->toBe('i-0already');
});

it('skips security group creation when one already exists', function () {
    // Retry after the SG was created but a later step failed — must not
    // recreate it (the fixed group name would collide).
    $server = Server::factory()->aws()->create([
        'provider_server_id' => 'i-0already',
        'provider_firewall_id' => 'sg-existing',
    ]);

    $awsService = Mockery::mock(AwsService::class);
    $awsService->shouldNotReceive('createInstance');
    $awsService->shouldNotReceive('createSecurityGroup');

    (new ProvisionAwsServerJob($server))->handle(mockCloudFactoryForAws($awsService), app(CloudInitScriptBuilder::class));

    $server->refresh();
    expect($server->provider_firewall_id)->toBe('sg-existing');
});

it('creates a provisioning_started event with aws provider', function () {
    $server = Server::factory()->aws()->create();

    $awsService = Mockery::mock(AwsService::class);
    $awsService->shouldReceive('createInstance')
        ->once()
        ->andReturn(['InstanceId' => 'i-0event', 'PublicIpAddress' => '10.0.0.9']);
    $awsService->shouldReceive('extractIpAddress')->andReturn('10.0.0.9');
    $awsService->shouldReceive('createSecurityGroup')->andReturn(['id' => 'sg-2']);

    (new ProvisionAwsServerJob($server))->handle(mockCloudFactoryForAws($awsService), app(CloudInitScriptBuilder::class));

    $event = $server->events()->where('event', 'provisioning_started')->first();
    expect($event)->not->toBeNull()
        ->and($event->payload['provider'])->toBe('aws');
});

it('terminates the orphaned instance on failure', function () {
    $server = Server::factory()->aws()->create([
        'provider_server_id' => 'i-0orphan',
    ]);

    $awsService = Mockery::mock(AwsService::class);
    $awsService->shouldReceive('terminateInstance')
        ->once()
        ->with('i-0orphan');

    app()->instance(CloudServiceFactory::class, mockCloudFactoryForAws($awsService));

    (new ProvisionAwsServerJob($server))->failed(new RuntimeException('Test failure'));
});

it('does nothing on failure when no instance was created', function () {
    $server = Server::factory()->aws()->create([
        'provider_server_id' => null,
    ]);

    $factory = Mockery::mock(CloudServiceFactory::class);
    $factory->shouldNotReceive('make');
    app()->instance(CloudServiceFactory::class, $factory);

    (new ProvisionAwsServerJob($server))->failed(new RuntimeException('Test failure'));
});
