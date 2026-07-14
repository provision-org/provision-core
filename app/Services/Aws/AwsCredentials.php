<?php

namespace App\Services\Aws;

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
        );
    }
}
