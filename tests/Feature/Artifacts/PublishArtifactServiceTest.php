<?php

use App\Enums\ArtifactType;
use App\Enums\ArtifactVisibility;
use App\Models\Agent;
use App\Models\AgentArtifact;
use App\Models\Server;
use App\Services\ArtifactAppService;
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

test('publishing an app artifact allocates a port and deploys the systemd unit', function () {
    $server = Server::factory()->running()->create();
    $agent = Agent::factory()->create(['name' => 'Luna', 'server_id' => $server->id]);

    $this->mock(CloudflareDnsService::class)->shouldReceive('isConfigured')->andReturnFalse();
    $this->mock(CaddyArtifactService::class)->shouldReceive('syncAgent');
    $this->mock(ArtifactAppService::class, function ($mock) {
        $mock->shouldReceive('allocatePort')->once()->andReturn(7000);
        $mock->shouldReceive('deploy')->once();
    });

    $artifact = app(PublishArtifactService::class)->publish($agent, [
        'name' => 'API', 'path_slug' => 'api', 'type' => ArtifactType::App,
        'start_command' => 'node server.js',
    ]);

    expect($artifact->type)->toBe(ArtifactType::App)
        ->and($artifact->port)->toBe(7000)
        ->and($artifact->status)->toBe('live');
});

test('unpublishing an app artifact removes its systemd unit', function () {
    $server = Server::factory()->running()->create();
    $agent = Agent::factory()->create(['server_id' => $server->id]);
    $artifact = AgentArtifact::factory()->live()->create([
        'agent_id' => $agent->id, 'team_id' => $agent->team_id,
        'type' => ArtifactType::App, 'port' => 7001,
    ]);

    $this->mock(CloudflareDnsService::class)->shouldReceive('isConfigured')->andReturnFalse();
    $this->mock(CaddyArtifactService::class)->shouldReceive('syncAgent');
    $this->mock(ArtifactAppService::class)->shouldReceive('remove')->once();

    app(PublishArtifactService::class)->unpublish($artifact);

    expect(AgentArtifact::find($artifact->id))->toBeNull();
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

test('teardownAgent stops app artifacts, removes the caddy site, and drops DNS', function () {
    $server = Server::factory()->running()->create();
    $agent = Agent::factory()->create(['name' => 'Luna', 'server_id' => $server->id]);

    $appArtifact = AgentArtifact::factory()->live()->create([
        'agent_id' => $agent->id, 'team_id' => $agent->team_id,
        'type' => ArtifactType::App, 'path_slug' => 'api', 'port' => 7001,
    ]);
    AgentArtifact::factory()->live()->create([
        'agent_id' => $agent->id, 'team_id' => $agent->team_id,
        'type' => ArtifactType::Static, 'path_slug' => 'report',
    ]);

    $this->mock(ArtifactAppService::class)
        ->shouldReceive('remove')->once()
        ->with(Mockery::on(fn ($a) => $a->is($agent)), Mockery::on(fn ($x) => $x->is($appArtifact)));

    $this->mock(CaddyArtifactService::class)
        ->shouldReceive('removeAgent')->once()->with(Mockery::on(fn ($a) => $a->is($agent)));

    $dns = $this->mock(CloudflareDnsService::class);
    $dns->shouldReceive('isConfigured')->andReturnTrue();
    $dns->shouldReceive('removeAgentRecord')->once()->with(Mockery::on(fn ($a) => $a->is($agent)));

    app(PublishArtifactService::class)->teardownAgent($agent);
});

test('teardownAgent skips DNS removal when Cloudflare is not configured', function () {
    $server = Server::factory()->running()->create();
    $agent = Agent::factory()->create(['server_id' => $server->id]);
    AgentArtifact::factory()->live()->create([
        'agent_id' => $agent->id, 'team_id' => $agent->team_id, 'path_slug' => 'report',
    ]);

    $this->mock(ArtifactAppService::class);
    $this->mock(CaddyArtifactService::class)->shouldReceive('removeAgent')->once();

    $dns = $this->mock(CloudflareDnsService::class);
    $dns->shouldReceive('isConfigured')->andReturnFalse();
    $dns->shouldReceive('removeAgentRecord')->never();

    app(PublishArtifactService::class)->teardownAgent($agent);
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
