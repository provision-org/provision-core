<?php

use App\Models\Agent;
use App\Models\AgentApiToken;
use App\Models\AgentArtifact;
use App\Models\Server;
use App\Services\ArtifactAppService;
use App\Services\CaddyArtifactService;
use App\Services\CloudflareDnsService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['cloudflare.artifact_domain' => 'provisionagents.com']);
    // No real SSH / DNS in tests.
    $this->mock(CaddyArtifactService::class)->shouldReceive('syncAgent');
    $this->mock(CloudflareDnsService::class)->shouldReceive('isConfigured')->andReturnFalse();
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
        ->assertJsonPath('public_url', 'https://luna.provisionagents.com/customer-dashboard/');

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

test('publishing requires a valid agent token', function () {
    $this->postJson('/api/artifacts', ['name' => 'Dash'])->assertUnauthorized();
});
