<?php

use App\Enums\CloudProvider;
use App\Models\Team;
use App\Models\TeamApiKey;
use App\Services\AwsService;
use App\Services\CloudServiceFactory;
use App\Services\DigitalOceanService;
use App\Services\HetznerService;
use App\Services\LinodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns hetzner service for a hetzner team', function () {
    $team = Team::factory()->create(['cloud_provider' => CloudProvider::Hetzner]);

    $service = app(CloudServiceFactory::class)->make($team);

    expect($service)->toBeInstanceOf(HetznerService::class);
});

it('returns digitalocean service for a digitalocean team', function () {
    $team = Team::factory()->create(['cloud_provider' => CloudProvider::DigitalOcean]);

    $service = app(CloudServiceFactory::class)->make($team);

    expect($service)->toBeInstanceOf(DigitalOceanService::class);
});

it('returns linode service for a linode team', function () {
    $team = Team::factory()->create(['cloud_provider' => CloudProvider::Linode]);

    $service = app(CloudServiceFactory::class)->make($team);

    expect($service)->toBeInstanceOf(LinodeService::class);
});

it('uses team cloud api key when available', function () {
    $team = Team::factory()->create(['cloud_provider' => CloudProvider::Hetzner]);

    TeamApiKey::factory()->create([
        'team_id' => $team->id,
        'provider_type' => 'cloud',
        'provider' => CloudProvider::Hetzner->value,
        'api_key' => 'team-specific-hetzner-token',
        'is_active' => true,
    ]);

    $service = app(CloudServiceFactory::class)->make($team);

    expect($service)->toBeInstanceOf(HetznerService::class);
});

it('falls back to global config when no team key exists', function () {
    $team = Team::factory()->create(['cloud_provider' => CloudProvider::Hetzner]);

    $service = app(CloudServiceFactory::class)->make($team);

    expect($service)->toBeInstanceOf(HetznerService::class);
});

it('ignores inactive team cloud api keys', function () {
    $team = Team::factory()->create(['cloud_provider' => CloudProvider::DigitalOcean]);

    TeamApiKey::factory()->create([
        'team_id' => $team->id,
        'provider_type' => 'cloud',
        'provider' => CloudProvider::DigitalOcean->value,
        'api_key' => 'inactive-token',
        'is_active' => false,
    ]);

    $service = app(CloudServiceFactory::class)->make($team);

    expect($service)->toBeInstanceOf(DigitalOceanService::class);
});

it('ignores llm api keys when resolving cloud token', function () {
    $team = Team::factory()->create(['cloud_provider' => CloudProvider::Hetzner]);

    TeamApiKey::factory()->create([
        'team_id' => $team->id,
        'provider_type' => 'llm',
        'provider' => CloudProvider::Hetzner->value,
        'api_key' => 'this-is-an-llm-key',
        'is_active' => true,
    ]);

    $service = app(CloudServiceFactory::class)->make($team);

    expect($service)->toBeInstanceOf(HetznerService::class);
});

it('returns aws service using the per-team JSON credentials key', function () {
    $team = Team::factory()->aws()->create();

    TeamApiKey::factory()->awsCloud()->create([
        'team_id' => $team->id,
        'api_key' => json_encode([
            'key_id' => 'AKIATEAMSPECIFIC1234',
            'secret' => 'team-secret',
            'region' => 'eu-central-1',
        ]),
    ]);

    $service = app(CloudServiceFactory::class)->make($team);

    expect($service)->toBeInstanceOf(AwsService::class)
        ->and($service->credentials()->keyId)->toBe('AKIATEAMSPECIFIC1234')
        ->and($service->credentials()->region)->toBe('eu-central-1');
});

it('falls back to the config aws block when no team key exists', function () {
    config()->set('cloud.aws.key_id', 'AKIAGLOBALFALLBACK00');
    config()->set('cloud.aws.secret', 'global-secret');
    config()->set('cloud.aws.default_region', 'us-west-2');

    $team = Team::factory()->aws()->create();

    $service = app(CloudServiceFactory::class)->make($team);

    expect($service)->toBeInstanceOf(AwsService::class)
        ->and($service->credentials()->keyId)->toBe('AKIAGLOBALFALLBACK00')
        ->and($service->credentials()->region)->toBe('us-west-2');
});

it('ignores inactive aws team keys and falls back to config', function () {
    config()->set('cloud.aws.key_id', 'AKIAGLOBALFALLBACK00');
    config()->set('cloud.aws.secret', 'global-secret');

    $team = Team::factory()->aws()->create();

    TeamApiKey::factory()->awsCloud()->inactive()->create([
        'team_id' => $team->id,
        'api_key' => json_encode([
            'key_id' => 'AKIAINACTIVE00000000',
            'secret' => 'inactive-secret',
        ]),
    ]);

    $service = app(CloudServiceFactory::class)->make($team);

    expect($service)->toBeInstanceOf(AwsService::class)
        ->and($service->credentials()->keyId)->toBe('AKIAGLOBALFALLBACK00');
});

it('can create a service for a specific provider via makeFor', function () {
    $team = Team::factory()->create(['cloud_provider' => CloudProvider::DigitalOcean]);

    $service = app(CloudServiceFactory::class)->makeFor($team, CloudProvider::Hetzner);

    expect($service)->toBeInstanceOf(HetznerService::class);
});
