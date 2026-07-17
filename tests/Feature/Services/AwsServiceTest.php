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
