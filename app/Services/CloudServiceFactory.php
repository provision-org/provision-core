<?php

namespace App\Services;

use App\Enums\CloudProvider;
use App\Models\Team;
use App\Services\Aws\AwsCredentials;

class CloudServiceFactory
{
    public function make(Team $team): HetznerService|DigitalOceanService|LinodeService|AwsService
    {
        return $this->makeFor($team, $team->cloudProvider());
    }

    public function makeFor(Team $team, CloudProvider $provider): HetznerService|DigitalOceanService|LinodeService|AwsService
    {
        $apiToken = $this->resolveApiToken($team, $provider);

        return match ($provider) {
            CloudProvider::Hetzner => new HetznerService($apiToken),
            CloudProvider::DigitalOcean => new DigitalOceanService($apiToken),
            CloudProvider::Linode => new LinodeService($apiToken),
            CloudProvider::Aws => new AwsService($this->resolveAwsCredentials($apiToken)),
        };
    }

    private function resolveApiToken(Team $team, CloudProvider $provider): ?string
    {
        $teamKey = $team->cloudApiKeys()
            ->where('provider', $provider->value)
            ->where('is_active', true)
            ->first();

        if ($teamKey) {
            return $teamKey->api_key;
        }

        return match ($provider) {
            CloudProvider::Hetzner => config('cloud.hetzner.api_token'),
            CloudProvider::DigitalOcean => config('cloud.digitalocean.api_token'),
            CloudProvider::Linode => config('cloud.linode.api_token'),
            CloudProvider::Aws => null,
        };
    }

    /**
     * AWS credentials are stored as encrypted JSON on the team's cloud
     * key (the BYO-AWS product path); the config/cloud.php aws block is
     * the global fallback for parity/testing.
     */
    private function resolveAwsCredentials(?string $teamKeyJson): AwsCredentials
    {
        if ($teamKeyJson) {
            return AwsCredentials::fromJson($teamKeyJson);
        }

        return AwsCredentials::fromConfig(config('cloud.aws', []));
    }
}
