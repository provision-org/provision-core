<?php

namespace App\Services;

use App\Enums\CloudProvider;
use App\Models\Team;
use App\Services\Aws\AwsCredentials;
use App\Services\Aws\BedrockCatalogService;
use App\Services\Aws\MantleCatalogService;
use App\Services\Aws\MantleTokenGenerator;
use Illuminate\Http\Client\Factory as HttpFactory;

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

    /**
     * Build an AwsService for ad-hoc credentials that are not (yet) stored
     * on a team — used to verify BYO-AWS keys before the team exists.
     * Kept on the factory so callers can mock it in tests.
     */
    public function makeAwsForCredentials(AwsCredentials $credentials): AwsService
    {
        return new AwsService($credentials);
    }

    /**
     * Build a BedrockCatalogService for the given credentials — used by the
     * wizard to list/verify the account's invocable models. Kept on the factory
     * so callers can mock it in tests (same seam as makeAwsForCredentials).
     */
    public function makeBedrockCatalogForCredentials(AwsCredentials $credentials): BedrockCatalogService
    {
        return new BedrockCatalogService($credentials);
    }

    /**
     * Build a MantleCatalogService for the given credentials — the Mantle
     * endpoint's model list + verify, reached with a short-term bearer token
     * minted from the credentials (no classic ConverseStream, no use-case form).
     * Same mockable seam as makeBedrockCatalogForCredentials.
     */
    public function makeMantleCatalogForCredentials(AwsCredentials $credentials): MantleCatalogService
    {
        return new MantleCatalogService($credentials, new MantleTokenGenerator, app(HttpFactory::class));
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
