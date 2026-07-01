<?php

use App\Enums\ArtifactType;
use App\Enums\ArtifactVisibility;
use App\Models\Agent;
use App\Models\AgentArtifact;
use App\Models\Server;
use App\Services\CaddyArtifactService;
use App\Services\CloudflareDnsService;
use App\Services\PublishArtifactService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => config(['cloudflare.artifact_domain' => 'provisionagents.com']));

test('publish creates a live artifact, ensures DNS, and syncs caddy', function () {
    $server = Server::factory()->running()->create();
    $agent = Agent::factory()->create(['name' => 'Luna', 'server_id' => $server->id]);

    $dns = $this->mock(CloudflareDnsService::class);
    $dns->shouldReceive('isConfigured')->andReturnTrue();
    $dns->shouldReceive('ensureAgentRecord')->once()->with(Mockery::on(fn ($a) => $a->is($agent)));

    $caddy = $this->mock(CaddyArtifactService::class);
    $caddy->shouldReceive('syncAgent')->once()->with(Mockery::on(fn ($a) => $a->is($agent)));

    $artifact = app(PublishArtifactService::class)->publish($agent, [
        'name' => 'Customer Dashboard',
        'path_slug' => 'dashboard',
        'type' => ArtifactType::Static,
        'source_dir' => 'dashboard',
        'visibility' => ArtifactVisibility::Public,
    ]);

    expect($artifact->status)->toBe('live')
        ->and($artifact->public_url)->toBe('https://luna.provisionagents.com/dashboard/')
        ->and($artifact->last_published_at)->not->toBeNull()
        ->and($artifact->type)->toBe(ArtifactType::Static);
});

test('re-publishing the same path updates the existing artifact', function () {
    $server = Server::factory()->running()->create();
    $agent = Agent::factory()->create(['name' => 'Luna', 'server_id' => $server->id]);

    $this->mock(CloudflareDnsService::class)->shouldReceive('isConfigured')->andReturnFalse();
    $this->mock(CaddyArtifactService::class)->shouldReceive('syncAgent');

    $service = app(PublishArtifactService::class);
    $service->publish($agent, ['name' => 'Dash', 'path_slug' => 'dash', 'source_dir' => 'v1']);
    $service->publish($agent, ['name' => 'Dash', 'path_slug' => 'dash', 'source_dir' => 'v2']);

    expect($agent->artifacts()->count())->toBe(1)
        ->and($agent->artifacts()->first()->source_dir)->toBe('v2');
});

test('publish marks the artifact errored and rethrows when caddy sync fails', function () {
    $server = Server::factory()->running()->create();
    $agent = Agent::factory()->create(['name' => 'Luna', 'server_id' => $server->id]);

    $this->mock(CloudflareDnsService::class)->shouldReceive('isConfigured')->andReturnFalse();
    $this->mock(CaddyArtifactService::class)
        ->shouldReceive('syncAgent')->andThrow(new RuntimeException('ssh down'));

    expect(fn () => app(PublishArtifactService::class)->publish($agent, [
        'name' => 'Dash', 'path_slug' => 'dash',
    ]))->toThrow(RuntimeException::class);

    expect($agent->artifacts()->first()->status)->toBe('error');
});

test('unpublish removes the artifact, re-syncs caddy, and drops DNS when none remain', function () {
    $server = Server::factory()->running()->create();
    $agent = Agent::factory()->create(['name' => 'Luna', 'server_id' => $server->id]);
    $artifact = AgentArtifact::factory()->live()->create([
        'agent_id' => $agent->id, 'team_id' => $agent->team_id,
    ]);

    $dns = $this->mock(CloudflareDnsService::class);
    $dns->shouldReceive('isConfigured')->andReturnTrue();
    $dns->shouldReceive('removeAgentRecord')->once();

    $this->mock(CaddyArtifactService::class)->shouldReceive('syncAgent')->once();

    app(PublishArtifactService::class)->unpublish($artifact);

    expect(AgentArtifact::find($artifact->id))->toBeNull();
});
