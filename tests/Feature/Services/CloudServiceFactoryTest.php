<?php

use App\Enums\CloudProvider;
use App\Models\Team;
use App\Models\TeamApiKey;
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

it('can create a service for a specific provider via makeFor', function () {
    $team = Team::factory()->create(['cloud_provider' => CloudProvider::DigitalOcean]);

    $service = app(CloudServiceFactory::class)->makeFor($team, CloudProvider::Hetzner);

    expect($service)->toBeInstanceOf(HetznerService::class);
});
