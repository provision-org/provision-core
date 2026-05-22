<?php

use App\Jobs\ProvisionDigitalOceanServerJob;
use App\Jobs\ProvisionHetznerServerJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

test('creating a team does not dispatch ProvisionHetznerServerJob', function () {
    Bus::fake();
    $user = User::factory()->withCompletedProfile()->create();

    $this->actingAs($user)->post(route('teams.store'), [
        'name' => 'My New Team',
        'harness_type' => 'hermes',
    ]);

    Bus::assertNotDispatched(ProvisionHetznerServerJob::class);
    Bus::assertNotDispatched(ProvisionDigitalOceanServerJob::class);
});

test('creating a team creates a server record', function () {
    Bus::fake();
    $user = User::factory()->withCompletedProfile()->create();

    $this->actingAs($user)->post(route('teams.store'), [
        'name' => 'My New Team',
        'harness_type' => 'hermes',
    ]);

    $team = $user->fresh()->currentTeam;

    expect($team)->not->toBeNull();
    expect($team->server)->not->toBeNull();
});

test('creating a team switches the user to the new team', function () {
    Bus::fake();
    $user = User::factory()->withCompletedProfile()->create();

    $this->actingAs($user)->post(route('teams.store'), [
        'name' => 'My New Team',
        'harness_type' => 'hermes',
    ]);

    $user->refresh();

    expect($user->current_team_id)->not->toBeNull()
        ->and($user->currentTeam->name)->toBe('My New Team');
});

test('server.region matches the chosen cloud provider, not the migration default (issue #30)', function () {
    Bus::fake();
    config()->set('cloud.provider_selection_enabled', true);
    $user = User::factory()->withCompletedProfile()->create();

    $this->actingAs($user)->post(route('teams.store'), [
        'name' => 'DO Team',
        'harness_type' => 'openclaw',
        'cloud_provider' => 'digitalocean',
    ]);

    $team = $user->fresh()->currentTeam;
    expect($team->server->cloud_provider->value)->toBe('digitalocean')
        ->and($team->server->region)->toBe('nyc1');
});

test('server.region uses provider-specific code for Hetzner', function () {
    Bus::fake();
    config()->set('cloud.provider_selection_enabled', true);
    $user = User::factory()->withCompletedProfile()->create();

    $this->actingAs($user)->post(route('teams.store'), [
        'name' => 'Hetzner Team',
        'harness_type' => 'openclaw',
        'cloud_provider' => 'hetzner',
    ]);

    $team = $user->fresh()->currentTeam;
    expect($team->server->cloud_provider->value)->toBe('hetzner')
        ->and($team->server->region)->toBe('ash');
});
