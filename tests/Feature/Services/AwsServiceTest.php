<?php

use App\Services\Aws\AwsCredentials;
use App\Services\AwsService;
use Aws\Command;
use Aws\Ec2\Ec2Client;
use Aws\Exception\AwsException;
use Aws\Result;
use Aws\Sts\StsClient;

beforeEach(function () {
    config([
        'cloud.aws.ami' => 'ami-test123',
        'cloud.aws.ssh_key_name' => null,
    ]);
});

function mockEc2ClientExpectingInstanceProfile(?string $expectedProfile): Ec2Client
{
    $client = Mockery::mock(Ec2Client::class);
    $client->shouldReceive('runInstances')
        ->once()
        ->with(Mockery::on(function (array $payload) use ($expectedProfile): bool {
            $actual = $payload['IamInstanceProfile']['Name'] ?? null;

            return $actual === $expectedProfile;
        }))
        ->andReturn(['Instances' => [['InstanceId' => 'i-0test']]]);

    return $client;
}

it('prefers the per-team instance profile from the cloud key JSON', function () {
    config(['cloud.aws.instance_profile' => 'global-profile']);

    $credentials = AwsCredentials::fromJson(json_encode([
        'key_id' => 'AKIATEAM000000000000',
        'secret' => 'team-secret',
        'region' => 'eu-central-1',
        'instance_profile' => 'team-bedrock-profile',
    ]));

    $service = new AwsService($credentials, mockEc2ClientExpectingInstanceProfile('team-bedrock-profile'));

    $instance = $service->createInstance(null, '#!/bin/bash');

    expect($credentials->instanceProfile)->toBe('team-bedrock-profile')
        ->and($instance['InstanceId'])->toBe('i-0test');
});

it('falls back to the global config instance profile when the team key has none', function () {
    config(['cloud.aws.instance_profile' => 'global-profile']);

    $credentials = AwsCredentials::fromJson(json_encode([
        'key_id' => 'AKIATEAM000000000000',
        'secret' => 'team-secret',
    ]));

    $service = new AwsService($credentials, mockEc2ClientExpectingInstanceProfile('global-profile'));

    $service->createInstance(null, '#!/bin/bash');

    expect($credentials->instanceProfile)->toBeNull();
});

it('omits the instance profile entirely when neither team nor config define one', function () {
    config(['cloud.aws.instance_profile' => null]);

    $credentials = new AwsCredentials('AKIATEAM000000000000', 'team-secret', 'us-east-1');

    $service = new AwsService($credentials, mockEc2ClientExpectingInstanceProfile(null));

    $service->createInstance(null, '#!/bin/bash');
});

it('waits for instance propagation before describing it', function () {
    $client = Mockery::mock(Ec2Client::class);
    // Eventual consistency: getInstance must wait for InstanceExists before
    // DescribeInstances, so a call right after RunInstances doesn't 404.
    $client->shouldReceive('waitUntil')
        ->once()
        ->with('InstanceExists', Mockery::on(fn (array $args): bool => $args['InstanceIds'] === ['i-0abc123']));
    $client->shouldReceive('describeInstances')
        ->once()
        ->with(['InstanceIds' => ['i-0abc123']])
        ->andReturn(new Result([
            'Reservations' => [['Instances' => [['InstanceId' => 'i-0abc123', 'VpcId' => 'vpc-9']]]],
        ]));

    $credentials = new AwsCredentials('AKIATEAM000000000000', 'team-secret', 'us-east-1');
    $service = new AwsService($credentials, $client);

    expect($service->getInstance('i-0abc123'))
        ->toMatchArray(['InstanceId' => 'i-0abc123', 'VpcId' => 'vpc-9']);
});

it('reports a default VPC as a usable launch network', function () {
    $client = Mockery::mock(Ec2Client::class);
    $client->shouldReceive('describeVpcs')
        ->once()
        ->with(Mockery::on(fn (array $a): bool => $a['Filters'][0]['Name'] === 'isDefault'))
        ->andReturn(new Result(['Vpcs' => [['VpcId' => 'vpc-default']]]));

    $service = new AwsService(new AwsCredentials('AKIA0000000000000000', 'secret', 'us-east-1'), $client);

    expect(fn () => $service->verifyLaunchNetwork())->not->toThrow(Exception::class);
});

it('rejects launch when the account has no default VPC and no subnet was given', function () {
    $client = Mockery::mock(Ec2Client::class);
    $client->shouldReceive('describeVpcs')->once()->andReturn(new Result(['Vpcs' => []]));

    $service = new AwsService(new AwsCredentials('AKIA0000000000000000', 'secret', 'us-east-1'), $client);

    expect(fn () => $service->verifyLaunchNetwork())
        ->toThrow(RuntimeException::class, 'no default VPC');
});

it('validates an explicit subnet instead of requiring a default VPC', function () {
    $client = Mockery::mock(Ec2Client::class);
    // With a subnet set, it must check the subnet — never DescribeVpcs.
    $client->shouldReceive('describeVpcs')->never();
    $client->shouldReceive('describeSubnets')
        ->once()
        ->with(['SubnetIds' => ['subnet-abc123']])
        ->andReturn(new Result(['Subnets' => [['SubnetId' => 'subnet-abc123', 'State' => 'available']]]));

    $service = new AwsService(
        new AwsCredentials('AKIA0000000000000000', 'secret', 'us-east-1', subnetId: 'subnet-abc123'),
        $client,
    );

    expect(fn () => $service->verifyLaunchNetwork())->not->toThrow(Exception::class);
});

it('rejects an unknown subnet', function () {
    $client = Mockery::mock(Ec2Client::class);
    $client->shouldReceive('describeSubnets')->once()->andReturn(new Result(['Subnets' => []]));

    $service = new AwsService(
        new AwsCredentials('AKIA0000000000000000', 'secret', 'us-east-1', subnetId: 'subnet-missing'),
        $client,
    );

    expect(fn () => $service->verifyLaunchNetwork())
        ->toThrow(RuntimeException::class, 'subnet-missing was not found');
});

it('launches into an explicit subnet with a forced public IP', function () {
    $client = Mockery::mock(Ec2Client::class);
    $client->shouldReceive('runInstances')
        ->once()
        ->with(Mockery::on(function (array $p): bool {
            // Uses a network interface (not top-level SubnetId) so the box always
            // gets a public IP even on a subnet with MapPublicIpOnLaunch=false.
            $ni = $p['NetworkInterfaces'][0] ?? null;

            return $ni !== null
                && $ni['SubnetId'] === 'subnet-abc123'
                && $ni['AssociatePublicIpAddress'] === true
                && ! isset($p['SubnetId']);
        }))
        ->andReturn(['Instances' => [['InstanceId' => 'i-0net']]]);

    $service = new AwsService(
        new AwsCredentials('AKIA0000000000000000', 'secret', 'us-east-1', subnetId: 'subnet-abc123'),
        $client,
    );

    expect($service->createInstance(null, '#!/bin/bash')['InstanceId'])->toBe('i-0net');
});

it('omits the network interface when no subnet is configured (default VPC path)', function () {
    $client = Mockery::mock(Ec2Client::class);
    $client->shouldReceive('runInstances')
        ->once()
        ->with(Mockery::on(fn (array $p): bool => ! isset($p['NetworkInterfaces'])))
        ->andReturn(['Instances' => [['InstanceId' => 'i-0def']]]);

    $service = new AwsService(new AwsCredentials('AKIA0000000000000000', 'secret', 'us-east-1'), $client);

    expect($service->createInstance(null, '#!/bin/bash')['InstanceId'])->toBe('i-0def');
});

it('waits for the InstanceTerminated state', function () {
    $client = Mockery::mock(Ec2Client::class);
    $client->shouldReceive('waitUntil')
        ->once()
        ->with('InstanceTerminated', Mockery::on(fn (array $args): bool => $args['InstanceIds'] === ['i-0abc123']));

    $credentials = new AwsCredentials('AKIATEAM000000000000', 'team-secret', 'us-east-1');
    $service = new AwsService($credentials, $client);

    $service->waitForInstanceTerminated('i-0abc123');
});

it('treats an already-reaped instance as already terminated', function () {
    $client = Mockery::mock(Ec2Client::class);
    $client->shouldReceive('terminateInstances')
        ->once()
        ->andThrow(new AwsException('InvalidInstanceID.NotFound: gone', new Command('TerminateInstances'), [
            'code' => 'InvalidInstanceID.NotFound',
        ]));

    $credentials = new AwsCredentials('AKIATEAM000000000000', 'team-secret', 'us-east-1');
    $service = new AwsService($credentials, $client);

    expect(fn () => $service->terminateInstance('i-0abc123'))->not->toThrow(Exception::class);
});

it('treats an already-reaped instance as terminated', function () {
    $client = Mockery::mock(Ec2Client::class);
    // A terminated instance eventually 404s from the waiter; that means gone.
    $client->shouldReceive('waitUntil')
        ->once()
        ->andThrow(new AwsException('InvalidInstanceID.NotFound: gone', new Command('DescribeInstances'), [
            'code' => 'InvalidInstanceID.NotFound',
        ]));

    $credentials = new AwsCredentials('AKIATEAM000000000000', 'team-secret', 'us-east-1');
    $service = new AwsService($credentials, $client);

    expect(fn () => $service->waitForInstanceTerminated('i-0abc123'))->not->toThrow(Exception::class);
});

it('verifies credentials via STS GetCallerIdentity', function () {
    $sts = Mockery::mock(StsClient::class);
    $sts->shouldReceive('getCallerIdentity')
        ->once()
        ->andReturn([
            'UserId' => 'AIDAEXAMPLE',
            'Account' => '123456789012',
            'Arn' => 'arn:aws:iam::123456789012:user/provision',
        ]);

    $credentials = new AwsCredentials('AKIATEAM000000000000', 'team-secret', 'us-east-1');
    $service = new AwsService($credentials, null, $sts);

    $identity = $service->verifyCredentials();

    expect($identity)->toBe([
        'account_id' => '123456789012',
        'arn' => 'arn:aws:iam::123456789012:user/provision',
    ]);
});

it('surfaces an STS auth failure as a readable RuntimeException', function () {
    $sts = Mockery::mock(StsClient::class);
    $sts->shouldReceive('getCallerIdentity')
        ->once()
        ->andThrow(new AwsException(
            'Error executing GetCallerIdentity',
            new Command('GetCallerIdentity'),
            ['message' => 'The security token included in the request is invalid.', 'code' => 'InvalidClientTokenId'],
        ));

    $credentials = new AwsCredentials('AKIABOGUS00000000000', 'wrong-secret', 'us-east-1');
    $service = new AwsService($credentials, null, $sts);

    expect(fn () => $service->verifyCredentials())
        ->toThrow(RuntimeException::class, 'AWS GetCallerIdentity failed: The security token included in the request is invalid.');
});
