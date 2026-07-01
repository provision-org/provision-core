<?php

use App\Enums\ArtifactVisibility;
use App\Models\Agent;
use App\Models\AgentApiToken;
use App\Models\AgentArtifact;
use App\Models\Server;
use App\Services\ArtifactAppService;
use App\Services\CaddyArtifactService;
use App\Services\CloudflareDnsService;
use App\Services\PublishArtifactService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => config(['cloudflare.artifact_domain' => 'provisionagents.com']));

test('gated artifacts are served behind a token check in the caddy config', function () {
    $agent = Agent::factory()->create(['name' => 'Luna', 'harness_agent_id' => 'agent-luna']);
    $artifact = AgentArtifact::factory()->live()->create([
        'agent_id' => $agent->id, 'team_id' => $agent->team_id,
        'path_slug' => 'report', 'source_dir' => 'report',
        'visibility' => ArtifactVisibility::Gated, 'access_token' => 'secret-token',
    ]);

    $config = app(CaddyArtifactService::class)->buildSiteConfig($agent, collect([$artifact]));

    expect($config)
        ->toContain('@ok query token=secret-token')
        ->toContain('handle @ok {')
        ->toContain('file_server')
        ->toContain('respond "Forbidden" 403');
});

test('publishing a gated artifact mints a token and puts it in the url', function () {
    $server = Server::factory()->running()->create();
    $agent = Agent::factory()->create(['name' => 'Luna', 'server_id' => $server->id]);

    $this->mock(CloudflareDnsService::class)->shouldReceive('isConfigured')->andReturnFalse();
    $this->mock(CaddyArtifactService::class)->shouldReceive('syncAgent');

    $artifact = app(PublishArtifactService::class)->publish($agent, [
        'name' => 'Q3 Report',
        'path_slug' => 'report',
        'visibility' => ArtifactVisibility::Gated,
    ]);

    expect($artifact->access_token)->not->toBeNull()
        ->and($artifact->public_url)->toBe("https://luna.provisionagents.com/report/?token={$artifact->access_token}");
});

test('an agent can publish a gated artifact via the api', function () {
    config(['cloudflare.artifact_domain' => 'provisionagents.com']);
    $this->mock(CaddyArtifactService::class)->shouldReceive('syncAgent');
    $this->mock(CloudflareDnsService::class)->shouldReceive('isConfigured')->andReturnFalse();
    $this->mock(ArtifactAppService::class);

    $server = Server::factory()->running()->create();
    $agent = Agent::factory()->create(['name' => 'Luna', 'server_id' => $server->id]);
    $token = AgentApiToken::createForAgent($agent)['plaintext'];

    $response = $this->withToken($token)->postJson('/api/artifacts', [
        'name' => 'Q3 Report',
        'visibility' => 'gated',
    ]);

    $response->assertCreated()->assertJsonPath('visibility', 'gated');
    expect($agent->artifacts()->first()->access_token)->not->toBeNull();
});
