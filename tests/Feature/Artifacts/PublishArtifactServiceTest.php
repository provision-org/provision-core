<?php

use App\Enums\ArtifactType;
use App\Enums\ArtifactVisibility;
use App\Models\Agent;
use App\Models\AgentArtifact;
use App\Models\Server;
use App\Services\ArtifactAppService;
use App\Services\ArtifactStaticService;
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
    $this->mock(ArtifactStaticService::class)->shouldReceive('deploy')->once();

    $artifact = app(PublishArtifactService::class)->publish($agent, [
        'name' => 'Customer Dashboard',
        'path_slug' => 'dashboard',
        'type' => ArtifactType::Static,
        'source_dir' => 'dashboard',
        'visibility' => ArtifactVisibility::Public,
    ]);

    expect($artifact->status)->toBe('live')
        ->and($artifact->public_url)->toBe("https://{$agent->slug}.provisionagents.com/dashboard/")
        ->and($artifact->last_published_at)->not->toBeNull()
        ->and($artifact->type)->toBe(ArtifactType::Static);
});

test('publishing an app artifact allocates a port and deploys the systemd unit', function () {
    $server = Server::factory()->running()->create();
    $agent = Agent::factory()->create(['name' => 'Luna', 'server_id' => $server->id]);

    $dns = $this->mock(CloudflareDnsService::class);
    $dns->shouldReceive('isConfigured')->andReturnTrue();
    $dns->shouldReceive('ensureAgentRecord')->once();
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

    $this->mock(CloudflareDnsService::class)->shouldReceive('removeAgentRecord')->once();
    $this->mock(CaddyArtifactService::class)->shouldReceive('syncAgent')->once()->with(
        Mockery::on(function (Agent $syncedAgent) use ($artifact): bool {
            $persisted = AgentArtifact::find($artifact->id);

            return $syncedAgent->id === $artifact->agent_id
                && $persisted?->status === 'stopped';
        }),
    );
    $this->mock(ArtifactAppService::class)->shouldReceive('removeArtifact')->once()->with(
        Mockery::on(fn (Agent $candidate) => $candidate->is($agent)),
        Mockery::on(fn (AgentArtifact $candidate) => $candidate->is($artifact)),
    );
    $this->mock(ArtifactStaticService::class)->shouldReceive('removeArtifact')->once()->with(
        Mockery::on(fn (Agent $candidate) => $candidate->is($agent)),
        Mockery::on(fn (AgentArtifact $candidate) => $candidate->is($artifact)),
    );

    app(PublishArtifactService::class)->unpublish($artifact);

    expect(AgentArtifact::find($artifact->id))->toBeNull();
});

test('unpublish keeps the artifact live when caddy cannot remove its route', function () {
    $server = Server::factory()->running()->create();
    $agent = Agent::factory()->create(['server_id' => $server->id]);
    $artifact = AgentArtifact::factory()->live()->create([
        'agent_id' => $agent->id,
        'team_id' => $agent->team_id,
        'type' => ArtifactType::App,
    ]);

    $this->mock(CaddyArtifactService::class)
        ->shouldReceive('syncAgent')
        ->once()
        ->andThrow(new RuntimeException('reload failed'));
    $this->mock(ArtifactAppService::class)->shouldReceive('removeArtifact')->never();
    $this->mock(ArtifactStaticService::class)->shouldReceive('removeArtifact')->never();
    $this->mock(CloudflareDnsService::class)->shouldReceive('isConfigured')->never();

    expect(fn () => app(PublishArtifactService::class)->unpublish($artifact))
        ->toThrow(RuntimeException::class, 'reload failed');

    expect($artifact->fresh()?->status)->toBe('live');
});

test('unpublish re-queries a stale route-bound artifact before removing deployments', function () {
    $server = Server::factory()->running()->create();
    $agent = Agent::factory()->create(['server_id' => $server->id]);
    $artifact = AgentArtifact::factory()->live()->create([
        'agent_id' => $agent->id,
        'team_id' => $agent->team_id,
        'deployment_key' => 'oldrevision00001',
    ]);

    AgentArtifact::query()->whereKey($artifact->id)->update([
        'deployment_key' => 'newrevision00002',
    ]);

    $this->mock(CaddyArtifactService::class)->shouldReceive('syncAgent')->once();
    $this->mock(CloudflareDnsService::class)->shouldReceive('removeAgentRecord')->once();
    $this->mock(ArtifactAppService::class)->shouldReceive('removeArtifact')->once()->with(
        Mockery::on(fn (Agent $candidate) => $candidate->is($agent)),
        Mockery::on(fn (AgentArtifact $candidate) => $candidate->deployment_key === 'newrevision00002'),
    );
    $this->mock(ArtifactStaticService::class)->shouldReceive('removeArtifact')->once()->with(
        Mockery::on(fn (Agent $candidate) => $candidate->is($agent)),
        Mockery::on(fn (AgentArtifact $candidate) => $candidate->deployment_key === 'newrevision00002'),
    );

    app(PublishArtifactService::class)->unpublish($artifact);

    expect($artifact->deployment_key)->toBe('oldrevision00001')
        ->and(AgentArtifact::find($artifact->id))->toBeNull();
});

test('re-publishing the same path updates the existing artifact', function () {
    $server = Server::factory()->running()->create();
    $agent = Agent::factory()->create(['name' => 'Luna', 'server_id' => $server->id]);

    $dns = $this->mock(CloudflareDnsService::class);
    $dns->shouldReceive('isConfigured')->andReturnTrue();
    $dns->shouldReceive('ensureAgentRecord')->twice();
    $this->mock(CaddyArtifactService::class)->shouldReceive('syncAgent');
    $static = $this->mock(ArtifactStaticService::class);
    $static->shouldReceive('deploy')->twice();
    $static->shouldReceive('removeStaleRevisions')->once()->with(
        Mockery::on(fn (Agent $candidate) => $candidate->is($agent)),
        Mockery::on(fn (AgentArtifact $candidate) => $candidate->source_dir === 'v2'),
    );
    $this->mock(ArtifactAppService::class)->shouldReceive('removeArtifact')->once()->with(
        Mockery::on(fn (Agent $candidate) => $candidate->is($agent)),
        Mockery::on(fn (AgentArtifact $candidate) => $candidate->source_dir === 'v2'),
    );

    $service = app(PublishArtifactService::class);
    $service->publish($agent, ['name' => 'Dash', 'path_slug' => 'dash', 'source_dir' => 'v1']);
    $service->publish($agent, ['name' => 'Dash', 'path_slug' => 'dash', 'source_dir' => 'v2']);

    expect($agent->artifacts()->count())->toBe(1)
        ->and($agent->artifacts()->first()->source_dir)->toBe('v2');
});

test('publish retains the live deployment and DNS when caddy sync has an ambiguous outcome', function () {
    $server = Server::factory()->running()->create();
    $agent = Agent::factory()->create(['name' => 'Luna', 'server_id' => $server->id]);

    $dns = $this->mock(CloudflareDnsService::class);
    $dns->shouldReceive('isConfigured')->andReturnTrue();
    $dns->shouldReceive('ensureAgentRecord')->once();
    $dns->shouldReceive('removeAgentRecord')->never();
    $this->mock(CaddyArtifactService::class)
        ->shouldReceive('syncAgent')->andThrow(new RuntimeException('ssh down'));
    $static = $this->mock(ArtifactStaticService::class);
    $static->shouldReceive('deploy')->once();
    $static->shouldReceive('remove')->never();

    expect(fn () => app(PublishArtifactService::class)->publish($agent, [
        'name' => 'Dash', 'path_slug' => 'dash',
    ]))->toThrow(RuntimeException::class);

    expect($agent->artifacts()->first()->status)->toBe('live')
        ->and($agent->fresh()->artifact_cleanup_required)->toBeTrue();
});

test('teardownAgent stops app artifacts, removes the caddy site, and drops DNS', function () {
    $server = Server::factory()->running()->create();
    $agent = Agent::factory()->create(['name' => 'Luna', 'server_id' => $server->id]);

    AgentArtifact::factory()->live()->create([
        'agent_id' => $agent->id, 'team_id' => $agent->team_id,
        'type' => ArtifactType::App, 'path_slug' => 'api', 'port' => 7001,
    ]);
    AgentArtifact::factory()->live()->create([
        'agent_id' => $agent->id, 'team_id' => $agent->team_id,
        'type' => ArtifactType::Static, 'path_slug' => 'report',
    ]);

    $apps = $this->mock(ArtifactAppService::class);
    $apps->shouldReceive('remove')->never();
    $apps->shouldReceive('removeAgent')->once()->with(Mockery::on(fn ($a) => $a->is($agent)));

    $this->mock(CaddyArtifactService::class)
        ->shouldReceive('removeAgent')->once()->with(Mockery::on(fn ($a) => $a->is($agent)));
    $this->mock(ArtifactStaticService::class)
        ->shouldReceive('removeAgent')->once()->with(Mockery::on(fn ($a) => $a->is($agent)));

    $dns = $this->mock(CloudflareDnsService::class);
    $dns->shouldReceive('removeAgentRecord')->once()->with(Mockery::on(fn ($a) => $a->is($agent)));

    app(PublishArtifactService::class)->teardownAgent($agent);
});

test('teardownAgent fails closed when DNS cleanup is unavailable', function () {
    $server = Server::factory()->running()->create();
    $agent = Agent::factory()->create(['server_id' => $server->id]);
    AgentArtifact::factory()->live()->create([
        'agent_id' => $agent->id, 'team_id' => $agent->team_id, 'path_slug' => 'report',
    ]);

    $this->mock(ArtifactAppService::class)->shouldReceive('removeAgent')->once();
    $this->mock(CaddyArtifactService::class)->shouldReceive('removeAgent')->once();
    $this->mock(ArtifactStaticService::class)->shouldReceive('removeAgent')->once();

    $dns = $this->mock(CloudflareDnsService::class);
    $dns->shouldReceive('removeAgentRecord')
        ->once()
        ->andThrow(new RuntimeException('Cloudflare DNS is not configured'));

    expect(fn () => app(PublishArtifactService::class)->teardownAgent($agent))
        ->toThrow(RuntimeException::class, 'Cloudflare DNS is not configured');
});

test('teardownAgent removes DNS even when the server relation is missing', function () {
    $agent = Agent::factory()->create(['server_id' => null]);
    AgentArtifact::factory()->live()->create([
        'agent_id' => $agent->id,
        'team_id' => $agent->team_id,
    ]);

    $this->mock(ArtifactAppService::class)->shouldReceive('remove')->never();
    $this->mock(CaddyArtifactService::class)->shouldReceive('removeAgent')->never();
    $this->mock(ArtifactStaticService::class)->shouldReceive('removeAgent')->never();

    $dns = $this->mock(CloudflareDnsService::class);
    $dns->shouldReceive('removeAgentRecord')->once()->with(Mockery::on(fn ($a) => $a->is($agent)));

    app(PublishArtifactService::class)->teardownAgent($agent);
});

test('teardownAgent attempts DNS cleanup after caddy cleanup fails', function () {
    $server = Server::factory()->running()->create();
    $agent = Agent::factory()->create(['server_id' => $server->id]);
    AgentArtifact::factory()->live()->create([
        'agent_id' => $agent->id,
        'team_id' => $agent->team_id,
    ]);

    $this->mock(ArtifactAppService::class)->shouldReceive('removeAgent')->once();
    $this->mock(CaddyArtifactService::class)
        ->shouldReceive('removeAgent')
        ->once()
        ->andThrow(new RuntimeException('caddy unavailable'));
    $this->mock(ArtifactStaticService::class)->shouldReceive('removeAgent')->once();

    $dns = $this->mock(CloudflareDnsService::class);
    $dns->shouldReceive('removeAgentRecord')->once();

    expect(fn () => app(PublishArtifactService::class)->teardownAgent($agent))
        ->toThrow(
            RuntimeException::class,
            'One or more artifact cleanup operations failed: caddy unavailable',
        );
});

test('teardownAgent can tolerate server cleanup failure before whole-server destruction', function () {
    $server = Server::factory()->running()->create();
    $agent = Agent::factory()->create(['server_id' => $server->id]);
    AgentArtifact::factory()->live()->create([
        'agent_id' => $agent->id,
        'team_id' => $agent->team_id,
    ]);

    $this->mock(ArtifactAppService::class)->shouldReceive('removeAgent')->once();
    $this->mock(CaddyArtifactService::class)
        ->shouldReceive('removeAgent')
        ->once()
        ->andThrow(new RuntimeException('server is already stopping'));
    $this->mock(ArtifactStaticService::class)->shouldReceive('removeAgent')->once();

    $dns = $this->mock(CloudflareDnsService::class);
    $dns->shouldReceive('removeAgentRecord')->once();

    app(PublishArtifactService::class)->teardownAgent($agent, requireServerCleanup: false);
});

test('teardownAgent skips remote cleanup when the agent has no artifact state', function () {
    $server = Server::factory()->running()->create();
    $agent = Agent::factory()->create(['server_id' => $server->id]);

    $this->mock(CaddyArtifactService::class)->shouldReceive('removeAgent')->never();
    $this->mock(ArtifactAppService::class)->shouldReceive('removeAgent')->never();
    $this->mock(ArtifactStaticService::class)->shouldReceive('removeAgent')->never();
    $this->mock(CloudflareDnsService::class)->shouldReceive('removeAgentRecord')->never();

    app(PublishArtifactService::class)->teardownAgent($agent);

    expect($agent->fresh()->artifact_cleanup_required)->toBeFalse();
});

test('teardownAgent sweeps remote state when cleanup is required without artifact rows', function () {
    $server = Server::factory()->running()->create();
    $agent = Agent::factory()->create([
        'server_id' => $server->id,
        'artifact_cleanup_required' => true,
    ]);

    $this->mock(CaddyArtifactService::class)->shouldReceive('removeAgent')->once()->with(
        Mockery::on(fn (Agent $candidate) => $candidate->is($agent)),
    );
    $this->mock(ArtifactAppService::class)->shouldReceive('removeAgent')->once()->with(
        Mockery::on(fn (Agent $candidate) => $candidate->is($agent)),
    );
    $this->mock(ArtifactStaticService::class)->shouldReceive('removeAgent')->once()->with(
        Mockery::on(fn (Agent $candidate) => $candidate->is($agent)),
    );
    $this->mock(CloudflareDnsService::class)->shouldReceive('removeAgentRecord')->never();

    app(PublishArtifactService::class)->teardownAgent($agent);

    expect($agent->fresh()->artifact_cleanup_required)->toBeFalse();
});

test('teardownAgent reconciles partial persisted DNS state without artifact rows', function () {
    $agent = Agent::factory()->create([
        'server_id' => null,
        'artifact_dns_record_id' => 'rec_partial',
        'artifact_dns_record_name' => null,
        'artifact_dns_zone_id' => 'zone-123',
    ]);

    $this->mock(CaddyArtifactService::class)->shouldReceive('removeAgent')->never();
    $this->mock(ArtifactAppService::class)->shouldReceive('removeAgent')->never();
    $this->mock(ArtifactStaticService::class)->shouldReceive('removeAgent')->never();
    $this->mock(CloudflareDnsService::class)->shouldReceive('removeAgentRecord')->once()->with(
        Mockery::on(fn (Agent $candidate) => $candidate->is($agent)),
    );

    app(PublishArtifactService::class)->teardownAgent($agent);
});

test('unpublish removes the artifact, re-syncs caddy, and drops DNS when none remain', function () {
    $server = Server::factory()->running()->create();
    $agent = Agent::factory()->create(['name' => 'Luna', 'server_id' => $server->id]);
    $artifact = AgentArtifact::factory()->live()->create([
        'agent_id' => $agent->id, 'team_id' => $agent->team_id,
    ]);

    $dns = $this->mock(CloudflareDnsService::class);
    $dns->shouldReceive('removeAgentRecord')->once();

    $this->mock(CaddyArtifactService::class)->shouldReceive('syncAgent')->once();
    $this->mock(ArtifactAppService::class)->shouldReceive('removeArtifact')->once()->with(
        Mockery::on(fn (Agent $candidate) => $candidate->is($agent)),
        Mockery::on(fn (AgentArtifact $candidate) => $candidate->is($artifact)),
    );
    $this->mock(ArtifactStaticService::class)->shouldReceive('removeArtifact')->once()->with(
        Mockery::on(fn (Agent $candidate) => $candidate->is($agent)),
        Mockery::on(fn (AgentArtifact $candidate) => $candidate->is($artifact)),
    );

    app(PublishArtifactService::class)->unpublish($artifact);

    expect(AgentArtifact::find($artifact->id))->toBeNull()
        ->and($agent->fresh()->artifact_cleanup_required)->toBeFalse();
});

test('a DNS failure during public to gated republish restores the prior live revision', function () {
    $server = Server::factory()->running()->create();
    $agent = Agent::factory()->create(['name' => 'Luna', 'server_id' => $server->id]);
    $oldUrl = "https://{$agent->slug}.provisionagents.com/dash/";
    $artifact = AgentArtifact::factory()->live()->create([
        'agent_id' => $agent->id,
        'team_id' => $agent->team_id,
        'path_slug' => 'dash',
        'source_dir' => 'v1',
        'visibility' => ArtifactVisibility::Public,
        'access_token' => null,
        'deployment_key' => 'oldrevision00001',
        'public_url' => $oldUrl,
    ]);

    $dns = $this->mock(CloudflareDnsService::class);
    $dns->shouldReceive('isConfigured')->andReturnTrue();
    $dns->shouldReceive('ensureAgentRecord')
        ->once()
        ->andThrow(new RuntimeException('DNS unavailable'));
    $dns->shouldReceive('removeAgentRecord')->never();
    $this->mock(CaddyArtifactService::class)->shouldReceive('syncAgent')->never();
    $static = $this->mock(ArtifactStaticService::class);
    $static->shouldReceive('deploy')->once();
    $static->shouldReceive('remove')->once();

    expect(fn () => app(PublishArtifactService::class)->publish($agent, [
        'name' => 'Dash',
        'path_slug' => 'dash',
        'source_dir' => 'v2',
        'visibility' => ArtifactVisibility::Gated,
    ]))->toThrow(RuntimeException::class, 'DNS unavailable');

    $restored = $artifact->fresh();

    expect($restored->status)->toBe('live')
        ->and($restored->visibility)->toBe(ArtifactVisibility::Public)
        ->and($restored->source_dir)->toBe('v1')
        ->and($restored->deployment_key)->toBe('oldrevision00001')
        ->and($restored->access_token)->toBeNull()
        ->and($restored->public_url)->toBe($oldUrl);
});

test('an ambiguous caddy republish keeps the new revision and skips stale deployment cleanup', function () {
    $server = Server::factory()->running()->create();
    $agent = Agent::factory()->create(['name' => 'Luna', 'server_id' => $server->id]);
    $artifact = AgentArtifact::factory()->live()->create([
        'agent_id' => $agent->id,
        'team_id' => $agent->team_id,
        'path_slug' => 'dash',
        'source_dir' => 'v1',
        'visibility' => ArtifactVisibility::Public,
        'access_token' => null,
        'deployment_key' => 'oldrevision00001',
        'public_url' => "https://{$agent->slug}.provisionagents.com/dash/",
    ]);

    $dns = $this->mock(CloudflareDnsService::class);
    $dns->shouldReceive('isConfigured')->andReturnTrue();
    $dns->shouldReceive('ensureAgentRecord')->once();
    $dns->shouldReceive('removeAgentRecord')->never();
    $this->mock(CaddyArtifactService::class)
        ->shouldReceive('syncAgent')
        ->once()
        ->andThrow(new RuntimeException('connection closed after reload'));
    $static = $this->mock(ArtifactStaticService::class);
    $static->shouldReceive('deploy')->once();
    $static->shouldReceive('remove')->never();
    $static->shouldReceive('removeStaleRevisions')->never();
    $this->mock(ArtifactAppService::class)->shouldReceive('removeArtifact')->never();

    expect(fn () => app(PublishArtifactService::class)->publish($agent, [
        'name' => 'Dash',
        'path_slug' => 'dash',
        'source_dir' => 'v2',
        'visibility' => ArtifactVisibility::Gated,
    ]))->toThrow(RuntimeException::class, 'connection closed after reload');

    $retained = $artifact->fresh();

    expect($retained->status)->toBe('live')
        ->and($retained->visibility)->toBe(ArtifactVisibility::Gated)
        ->and($retained->source_dir)->toBe('v2')
        ->and($retained->deployment_key)->not->toBe('oldrevision00001')
        ->and($retained->access_token)->not->toBeNull()
        ->and($retained->public_url)->toContain('?token=');
});

test('an app to static republish removes obsolete app and static revisions after routing switches', function () {
    $server = Server::factory()->running()->create();
    $agent = Agent::factory()->create(['server_id' => $server->id]);
    $previous = AgentArtifact::factory()->live()->create([
        'agent_id' => $agent->id,
        'team_id' => $agent->team_id,
        'path_slug' => 'dash',
        'type' => ArtifactType::App,
        'start_command' => 'node server.js',
        'port' => 7000,
        'deployment_key' => 'oldapprevision01',
    ]);

    $dns = $this->mock(CloudflareDnsService::class);
    $dns->shouldReceive('isConfigured')->andReturnTrue();
    $dns->shouldReceive('ensureAgentRecord')->once();
    $this->mock(CaddyArtifactService::class)->shouldReceive('syncAgent')->once();
    $static = $this->mock(ArtifactStaticService::class);
    $static->shouldReceive('deploy')->once();
    $static->shouldReceive('removeStaleRevisions')->once()->with(
        Mockery::on(fn (Agent $candidate) => $candidate->is($agent)),
        Mockery::on(fn (AgentArtifact $candidate) => $candidate->type === ArtifactType::Static
            && $candidate->deployment_key !== $previous->deployment_key),
    );
    $this->mock(ArtifactAppService::class)->shouldReceive('removeArtifact')->once()->with(
        Mockery::on(fn (Agent $candidate) => $candidate->is($agent)),
        Mockery::on(fn (AgentArtifact $candidate) => $candidate->is($previous)
            && $candidate->type === ArtifactType::Static),
    );

    $published = app(PublishArtifactService::class)->publish($agent, [
        'name' => 'Dash',
        'path_slug' => 'dash',
        'source_dir' => 'static-dash',
        'type' => ArtifactType::Static,
    ]);

    expect($published->type)->toBe(ArtifactType::Static)
        ->and($published->deployment_key)->not->toBe($previous->deployment_key);
});

test('an app republish allocates its replacement port before releasing the live revision', function () {
    $server = Server::factory()->running()->create();
    $agent = Agent::factory()->create(['server_id' => $server->id]);
    $previous = AgentArtifact::factory()->live()->create([
        'agent_id' => $agent->id,
        'team_id' => $agent->team_id,
        'path_slug' => 'api',
        'type' => ArtifactType::App,
        'start_command' => 'node v1.js',
        'port' => 7000,
        'deployment_key' => 'oldapprevision01',
    ]);

    $dns = $this->mock(CloudflareDnsService::class);
    $dns->shouldReceive('isConfigured')->andReturnTrue();
    $dns->shouldReceive('ensureAgentRecord')->once();
    $this->mock(CaddyArtifactService::class)->shouldReceive('syncAgent')->once();

    $apps = $this->mock(ArtifactAppService::class);
    $apps->shouldReceive('allocatePort')->once()->with(
        Mockery::on(fn (Server $candidate) => $candidate->is($server)),
    )->andReturnUsing(
        fn () => (int) AgentArtifact::query()
            ->where('agent_id', $agent->id)
            ->value('port') + 1,
    );
    $apps->shouldReceive('deploy')->once()->with(
        Mockery::on(fn (Agent $candidate) => $candidate->is($agent)),
        Mockery::on(fn (AgentArtifact $candidate) => $candidate->port === 7001),
    );
    $apps->shouldReceive('removeStaleRevisions')->once()->with(
        Mockery::on(fn (Agent $candidate) => $candidate->is($agent)),
        Mockery::on(fn (AgentArtifact $candidate) => $candidate->port === 7001
            && $candidate->deployment_key !== $previous->deployment_key),
    );
    $this->mock(ArtifactStaticService::class)->shouldReceive('removeArtifact')->once()->with(
        Mockery::on(fn (Agent $candidate) => $candidate->is($agent)),
        Mockery::on(fn (AgentArtifact $candidate) => $candidate->is($previous)
            && $candidate->type === ArtifactType::App),
    );

    $published = app(PublishArtifactService::class)->publish($agent, [
        'name' => 'API',
        'path_slug' => 'api',
        'type' => ArtifactType::App,
        'start_command' => 'node v2.js',
    ]);

    expect($published->port)->toBe(7001)
        ->and($published->deployment_key)->not->toBe($previous->deployment_key);
});
