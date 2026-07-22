<?php

use App\Enums\CloudProvider;
use App\Enums\HarnessType;
use App\Models\Agent;
use App\Models\AgentApiToken;
use App\Models\AgentArtifact;
use App\Models\Server;
use App\Services\ArtifactAppService;
use App\Services\ArtifactStaticService;
use App\Services\CaddyArtifactService;
use App\Services\CloudflareDnsService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['cloudflare.artifact_domain' => 'provisionagents.com']);
    // No real SSH / DNS in tests.
    $this->mock(CaddyArtifactService::class)->shouldReceive('syncAgent');
    $static = $this->mock(ArtifactStaticService::class);
    $static->shouldReceive('deploy')->byDefault();
    $static->shouldReceive('remove')->byDefault();
    $static->shouldReceive('removeArtifact')->byDefault();
    $static->shouldReceive('removeStaleRevisions')->byDefault();
    $apps = $this->mock(ArtifactAppService::class);
    $apps->shouldReceive('removeArtifact')->byDefault();
    $apps->shouldReceive('removeStaleRevisions')->byDefault();
    $dns = $this->mock(CloudflareDnsService::class);
    $dns->shouldReceive('isConfigured')->andReturnTrue();
    $dns->shouldReceive('ensureAgentRecord')->byDefault();
    $dns->shouldReceive('removeAgentRecord')->byDefault();
});

/**
 * @return array{0: Agent, 1: string}
 */
function artifactApiAgent(): array
{
    $server = Server::factory()->running()->create();
    $agent = Agent::factory()->create(['name' => 'Luna', 'server_id' => $server->id]);

    return [$agent, AgentApiToken::createForAgent($agent)['plaintext']];
}

test('an agent can publish a static artifact', function () {
    [$agent, $token] = artifactApiAgent();

    $response = $this->withToken($token)->postJson('/api/artifacts', [
        'name' => 'Customer Dashboard',
    ]);

    $response->assertCreated()
        ->assertJsonPath('path_slug', 'customer-dashboard')
        ->assertJsonPath('status', 'live')
        ->assertJsonPath('public_url', "https://{$agent->slug}.provisionagents.com/customer-dashboard/");

    expect($agent->artifacts()->count())->toBe(1);
});

test('an agent can publish an app artifact with a start command', function () {
    [$agent, $token] = artifactApiAgent();
    $this->mock(ArtifactAppService::class, function ($mock) {
        $mock->shouldReceive('allocatePort')->andReturn(7000);
        $mock->shouldReceive('deploy');
    });

    $this->withToken($token)->postJson('/api/artifacts', [
        'name' => 'API Tool',
        'type' => 'app',
        'start_command' => 'node server.js',
    ])->assertCreated()->assertJsonPath('type', 'app');

    expect($agent->artifacts()->first()->port)->toBe(7000);
});

test('an app artifact requires a start command', function () {
    [, $token] = artifactApiAgent();

    $this->withToken($token)->postJson('/api/artifacts', [
        'name' => 'API Tool',
        'type' => 'app',
    ])->assertStatus(422);
});

test('publishing requires the agent to have a server', function () {
    $agent = Agent::factory()->create(['name' => 'Luna', 'server_id' => null]);
    $token = AgentApiToken::createForAgent($agent)['plaintext'];

    $this->withToken($token)->postJson('/api/artifacts', ['name' => 'Dash'])
        ->assertStatus(422);
});

test('an agent can list and unpublish its artifacts', function () {
    [$agent, $token] = artifactApiAgent();
    $artifact = AgentArtifact::factory()->live()->create([
        'agent_id' => $agent->id, 'team_id' => $agent->team_id,
    ]);

    $this->withToken($token)->getJson('/api/artifacts')
        ->assertOk()->assertJsonCount(1);

    $this->withToken($token)->deleteJson("/api/artifacts/{$artifact->id}")
        ->assertOk();

    expect(AgentArtifact::find($artifact->id))->toBeNull();
});

test('artifact responses expose only the public projection', function () {
    [$agent, $token] = artifactApiAgent();
    $this->mock(ArtifactAppService::class, function ($mock) {
        $mock->shouldReceive('allocatePort')->andReturn(7000);
        $mock->shouldReceive('deploy');
    });

    $response = $this->withToken($token)->postJson('/api/artifacts', [
        'name' => 'Private App',
        'type' => 'app',
        'start_command' => 'TOKEN=secret-value node server.js',
        'visibility' => 'gated',
    ]);

    $response->assertCreated()
        ->assertJsonMissingPath('start_command')
        ->assertJsonMissingPath('access_token')
        ->assertJsonMissingPath('source_dir')
        ->assertJsonMissingPath('port')
        ->assertJsonMissingPath('error_message');

    expect($response->getContent())->not->toContain('secret-value');
});

test('an agent cannot unpublish another agent artifact', function () {
    [, $token] = artifactApiAgent();
    $other = AgentArtifact::factory()->live()->create();

    $this->withToken($token)->deleteJson("/api/artifacts/{$other->id}")
        ->assertNotFound();

    expect(AgentArtifact::find($other->id))->not->toBeNull();
});

test('publishing rejects an invalid path slug', function () {
    [, $token] = artifactApiAgent();

    $this->withToken($token)->postJson('/api/artifacts', [
        'name' => 'Dash',
        'path_slug' => '../etc/passwd',
    ])->assertStatus(422);
});

test('publishing rejects unsafe source directories', function (string $sourceDir) {
    [, $token] = artifactApiAgent();

    $this->withToken($token)->postJson('/api/artifacts', [
        'name' => 'Dash',
        'source_dir' => $sourceDir,
    ])->assertUnprocessable();
})->with([
    'parent traversal' => 'safe/../../other-agent',
    'current directory segment' => 'safe/./nested',
    'empty segment' => 'safe//nested',
    'hidden root' => '.env',
]);

test('publishing accepts a safe nested source directory', function () {
    [, $token] = artifactApiAgent();

    $this->withToken($token)->postJson('/api/artifacts', [
        'name' => 'Dash',
        'source_dir' => 'reports/2026-q3/v1.2',
    ])->assertCreated();
});

test('publishing rejects a name that cannot produce a path slug', function () {
    [, $token] = artifactApiAgent();

    $this->withToken($token)->postJson('/api/artifacts', [
        'name' => '🎉',
    ])->assertUnprocessable();
});

test('publishing is unavailable for Hermes agents', function () {
    $server = Server::factory()->running()->create();
    $agent = Agent::factory()->create([
        'server_id' => $server->id,
        'harness_type' => HarnessType::Hermes,
    ]);
    $token = AgentApiToken::createForAgent($agent)['plaintext'];

    $this->withToken($token)->postJson('/api/artifacts', ['name' => 'Dash'])
        ->assertUnprocessable();
});

test('publishing is unavailable for local Docker agents', function () {
    $server = Server::factory()->running()->create(['cloud_provider' => CloudProvider::Docker]);
    $agent = Agent::factory()->create(['server_id' => $server->id]);
    $token = AgentApiToken::createForAgent($agent)['plaintext'];

    $this->withToken($token)->postJson('/api/artifacts', ['name' => 'Dash'])
        ->assertUnprocessable();
});

test('publishing fails closed when the artifact domain is not configured', function () {
    config(['cloudflare.artifact_domain' => null]);
    [, $token] = artifactApiAgent();

    $this->withToken($token)->postJson('/api/artifacts', ['name' => 'Dash'])
        ->assertServiceUnavailable();
});

test('publishing fails closed when managed DNS credentials are incomplete', function () {
    $this->mock(CloudflareDnsService::class)->shouldReceive('isConfigured')->andReturnFalse();
    [, $token] = artifactApiAgent();

    $this->withToken($token)->postJson('/api/artifacts', ['name' => 'Dash'])
        ->assertServiceUnavailable();
});

test('artifact operations are rate limited per agent', function () {
    config(['artifacts.operations_per_minute' => 2]);
    [, $token] = artifactApiAgent();

    $this->withToken($token)->postJson('/api/artifacts', ['name' => 'One'])->assertCreated();
    $this->withToken($token)->postJson('/api/artifacts', ['name' => 'Two'])->assertCreated();
    $this->withToken($token)->postJson('/api/artifacts', ['name' => 'Three'])->assertTooManyRequests();
});

test('an agent cannot exceed its total artifact quota', function () {
    config(['artifacts.max_per_agent' => 1]);
    [$agent, $token] = artifactApiAgent();
    AgentArtifact::factory()->live()->create([
        'agent_id' => $agent->id,
        'team_id' => $agent->team_id,
    ]);

    $this->withToken($token)->postJson('/api/artifacts', ['name' => 'Another'])
        ->assertUnprocessable();
});

test('an agent cannot exceed its running app quota', function () {
    config(['artifacts.max_apps_per_agent' => 1]);
    [$agent, $token] = artifactApiAgent();
    AgentArtifact::factory()->live()->create([
        'agent_id' => $agent->id,
        'team_id' => $agent->team_id,
        'type' => 'app',
        'port' => 7000,
    ]);

    $this->withToken($token)->postJson('/api/artifacts', [
        'name' => 'Another App',
        'type' => 'app',
        'start_command' => 'node server.js',
    ])->assertUnprocessable();
});

test('publishing requires a valid agent token', function () {
    $this->postJson('/api/artifacts', ['name' => 'Dash'])->assertUnauthorized();
});
