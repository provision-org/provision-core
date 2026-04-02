<?php

namespace App\Services;

use App\Enums\CloudProvider;
use App\Models\Team;

class CloudServiceFactory
{
    public function make(Team $team): HetznerService|DigitalOceanService|LinodeService
    {
        $provider = $team->cloudProvider();
        $apiToken = $this->resolveApiToken($team, $provider);

        return match ($provider) {
            CloudProvider::Hetzner => new HetznerService($apiToken),
            CloudProvider::DigitalOcean => new DigitalOceanService($apiToken),
            CloudProvider::Linode => new LinodeService($apiToken),
        };
    }

    public function makeFor(Team $team, CloudProvider $provider): HetznerService|DigitalOceanService|LinodeService
    {
        $apiToken = $this->resolveApiToken($team, $provider);

        return match ($provider) {
            CloudProvider::Hetzner => new HetznerService($apiToken),
            CloudProvider::DigitalOcean => new DigitalOceanService($apiToken),
            CloudProvider::Linode => new LinodeService($apiToken),
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
        };
    }
}
