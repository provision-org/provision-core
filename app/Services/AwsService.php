<?php

namespace App\Services;

use App\Models\Team;
use App\Services\Aws\AwsCredentials;
use Aws\Ec2\Ec2Client;
use Aws\Exception\AwsException;
use Aws\Sts\StsClient;
use RuntimeException;

class AwsService
{
    private const CANONICAL_OWNER_ID = '099720109477';

    private Ec2Client $client;

    private StsClient $stsClient;

    public function __construct(private readonly AwsCredentials $credentials, ?Ec2Client $client = null, ?StsClient $stsClient = null)
    {
        $clientConfig = [
            'version' => 'latest',
            'region' => $credentials->region,
            'credentials' => [
                'key' => $credentials->keyId,
                'secret' => $credentials->secret,
            ],
        ];

        $this->client = $client ?? new Ec2Client($clientConfig);
        $this->stsClient = $stsClient ?? new StsClient($clientConfig);
    }

    public function credentials(): AwsCredentials
    {
        return $this->credentials;
    }

    /**
     * Verify the credentials against AWS via STS GetCallerIdentity — the
     * canonical "are these keys real" call (no permissions required beyond
     * the keys themselves being valid).
     *
     * @return array{account_id: string, arn: string}
     */
    public function verifyCredentials(): array
    {
        $result = $this->execute('GetCallerIdentity', fn (): mixed => $this->stsClient->getCallerIdentity());

        return [
            'account_id' => $result['Account'] ?? '',
            'arn' => $result['Arn'] ?? '',
        ];
    }

    /**
     * Launch an EC2 instance running the given cloud-init script. Uses a
     * single 80GB gp3 root volume — no external volume like the other
     * providers. Returns the Instance array from RunInstances.
     *
     * @return array<string, mixed>
     */
    public function createInstance(?Team $team, string $cloudInitScript, ?string $instanceType = null, ?string $region = null, ?string $hostname = null): array
    {
        $name = $hostname ?? 'provision-'.($team?->id ?? 'server').'-'.now()->timestamp;

        $tags = [
            ['Key' => 'Name', 'Value' => $name],
        ];

        if ($team) {
            $tags[] = ['Key' => 'provision:team', 'Value' => (string) $team->id];
        }

        $payload = [
            'ImageId' => $this->resolveAmi(),
            'InstanceType' => $instanceType ?? 't3.large',
            'MinCount' => 1,
            'MaxCount' => 1,
            'UserData' => base64_encode($cloudInitScript),
            'BlockDeviceMappings' => [
                [
                    'DeviceName' => '/dev/sda1',
                    'Ebs' => [
                        'VolumeSize' => 80,
                        'VolumeType' => 'gp3',
                        'DeleteOnTermination' => true,
                    ],
                ],
            ],
            'TagSpecifications' => [
                ['ResourceType' => 'instance', 'Tags' => $tags],
            ],
        ];

        $keyName = $this->credentials->sshKeyName ?? config('cloud.aws.ssh_key_name');
        if ($keyName) {
            $payload['KeyName'] = $keyName;
        }

        // Optional instance profile — enables keyless Bedrock access later.
        // A per-team profile from the cloud key JSON wins over the global config.
        $instanceProfile = $this->credentials->instanceProfile ?? config('cloud.aws.instance_profile');
        if ($instanceProfile) {
            $payload['IamInstanceProfile'] = ['Name' => $instanceProfile];
        }

        $result = $this->execute('RunInstances', fn (): mixed => $this->client->runInstances($payload));

        return $result['Instances'][0];
    }

    /**
     * Create a security group in the instance's VPC allowing inbound
     * 22/80/443 (matches the other providers' firewall posture) and
     * attach it to the instance.
     *
     * @return array<string, mixed>
     */
    public function createSecurityGroup(string $name, string $instanceId): array
    {
        $instance = $this->getInstance($instanceId);
        $vpcId = $instance['VpcId'] ?? null;

        $params = [
            'GroupName' => $name,
            'Description' => 'Provision server firewall (SSH/HTTP/HTTPS)',
        ];

        if ($vpcId) {
            $params['VpcId'] = $vpcId;
        }

        $created = $this->execute('CreateSecurityGroup', fn (): mixed => $this->client->createSecurityGroup($params));
        $groupId = $created['GroupId'];

        $this->execute('AuthorizeSecurityGroupIngress', fn (): mixed => $this->client->authorizeSecurityGroupIngress([
            'GroupId' => $groupId,
            'IpPermissions' => array_map(fn (int $port): array => [
                'IpProtocol' => 'tcp',
                'FromPort' => $port,
                'ToPort' => $port,
                'IpRanges' => [['CidrIp' => '0.0.0.0/0']],
                'Ipv6Ranges' => [['CidrIpv6' => '::/0']],
            ], [22, 80, 443]),
        ]));

        $this->execute('ModifyInstanceAttribute', fn (): mixed => $this->client->modifyInstanceAttribute([
            'InstanceId' => $instanceId,
            'Groups' => [$groupId],
        ]));

        return ['id' => $groupId];
    }

    /**
     * @return array<string, mixed>
     */
    public function getInstance(string $instanceId): array
    {
        $result = $this->execute('DescribeInstances', fn (): mixed => $this->client->describeInstances([
            'InstanceIds' => [$instanceId],
        ]));

        return $result['Reservations'][0]['Instances'][0] ?? [];
    }

    /**
     * Extract the public IPv4 from an EC2 instance array. May be null
     * until the instance reaches the running state.
     */
    public function extractIpAddress(array $instance): ?string
    {
        return $instance['PublicIpAddress'] ?? null;
    }

    public function terminateInstance(string $instanceId): void
    {
        $this->execute('TerminateInstances', fn (): mixed => $this->client->terminateInstances([
            'InstanceIds' => [$instanceId],
        ]));
    }

    /**
     * Delete a security group. The group can't be deleted while still
     * attached to a (terminating) instance, so retry briefly on
     * DependencyViolation. Treats a missing group as already-gone.
     */
    public function deleteSecurityGroup(string $groupId): void
    {
        $delays = [0, 5, 10, 20];

        foreach ($delays as $i => $delay) {
            if ($delay > 0) {
                sleep($delay);
            }

            try {
                $this->client->deleteSecurityGroup(['GroupId' => $groupId]);

                return;
            } catch (AwsException $e) {
                $code = $e->getAwsErrorCode();

                if (in_array($code, ['InvalidGroup.NotFound', 'InvalidGroupId.NotFound'], true)) {
                    return;
                }

                if ($code !== 'DependencyViolation' || $i === count($delays) - 1) {
                    throw new RuntimeException("AWS DeleteSecurityGroup failed: {$this->readableMessage($e)}", 0, $e);
                }
            }
        }
    }

    /**
     * Resolve the AMI to launch: the configured AMI if set, otherwise the
     * latest Ubuntu 24.04 LTS amd64 image published by Canonical.
     */
    private function resolveAmi(): string
    {
        $configured = config('cloud.aws.ami');

        if ($configured) {
            return $configured;
        }

        $result = $this->execute('DescribeImages', fn (): mixed => $this->client->describeImages([
            'Owners' => [self::CANONICAL_OWNER_ID],
            'Filters' => [
                ['Name' => 'name', 'Values' => ['ubuntu/images/hvm-ssd*/ubuntu-noble-24.04-amd64-server-*']],
                ['Name' => 'state', 'Values' => ['available']],
                ['Name' => 'architecture', 'Values' => ['x86_64']],
            ],
        ]));

        $images = $result['Images'] ?? [];

        if ($images === []) {
            throw new RuntimeException('AWS DescribeImages returned no Ubuntu 24.04 LTS AMI.');
        }

        usort($images, fn (array $a, array $b): int => strcmp($b['CreationDate'] ?? '', $a['CreationDate'] ?? ''));

        return $images[0]['ImageId'];
    }

    /**
     * Run an EC2 API call, surfacing AwsException as a RuntimeException
     * with a readable message (mirrors how the HTTP-based provider
     * services surface request errors).
     */
    private function execute(string $operation, callable $call): mixed
    {
        try {
            return $call();
        } catch (AwsException $e) {
            throw new RuntimeException("AWS {$operation} failed: {$this->readableMessage($e)}", 0, $e);
        }
    }

    private function readableMessage(AwsException $e): string
    {
        return $e->getAwsErrorMessage() ?? $e->getMessage();
    }
}
