<?php

namespace App\Services\Aws;

use App\Enums\CloudProvider;
use App\Models\Team;
use InvalidArgumentException;

/**
 * Value object holding a team's (or the platform's) AWS credentials.
 * Per-team credentials are stored as encrypted JSON on TeamApiKey;
 * the global config block in config/cloud.php acts as a fallback.
 */
readonly class AwsCredentials
{
    public function __construct(
        public string $keyId,
        public string $secret,
        public string $region,
        public ?string $sshKeyName = null,
        public ?string $instanceProfile = null,
    ) {}

    /**
     * Build credentials from the encrypted JSON payload stored on a
     * TeamApiKey (provider_type=cloud, provider=aws).
     */
    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true);

        if (! is_array($data) || empty($data['key_id']) || empty($data['secret'])) {
            throw new InvalidArgumentException('Invalid AWS credentials payload.');
        }

        return new self(
            keyId: $data['key_id'],
            secret: $data['secret'],
            region: $data['region'] ?? config('cloud.aws.default_region', 'us-east-1'),
            sshKeyName: $data['ssh_key_name'] ?? null,
            instanceProfile: $data['instance_profile'] ?? null,
        );
    }

    /**
     * Build credentials from the config/cloud.php aws block.
     *
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config): self
    {
        if (empty($config['key_id']) || empty($config['secret'])) {
            throw new InvalidArgumentException('AWS credentials are not configured.');
        }

        return new self(
            keyId: $config['key_id'],
            secret: $config['secret'],
            region: $config['default_region'] ?? 'us-east-1',
            sshKeyName: $config['ssh_key_name'] ?? null,
            instanceProfile: $config['instance_profile'] ?? null,
        );
    }

    /**
     * Resolve the AWS region for a team from its cloud TeamApiKey JSON,
     * falling back to the global config default. Used to point the OpenClaw
     * amazon-bedrock plugin (and AWS_REGION env) at the region the team's
     * EC2 server actually runs in — never exposes the key/secret.
     */
    public static function regionForTeam(Team $team): string
    {
        $cloudKey = $team->cloudApiKeys()
            ->where('provider', CloudProvider::Aws->value)
            ->where('is_active', true)
            ->first();

        if ($cloudKey) {
            try {
                return self::fromJson($cloudKey->api_key)->region;
            } catch (InvalidArgumentException) {
                // Malformed payload — fall through to the config default.
            }
        }

        return config('cloud.aws.default_region', 'us-east-1');
    }
}
