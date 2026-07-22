<?php

use App\Enums\AgentStatus;
use App\Enums\HarnessType;
use App\Models\Agent;
use App\Models\AgentApiToken;
use App\Models\AgentArtifact;
use App\Models\Server;
use App\Models\User;
use App\Services\CaddyArtifactService;
use App\Services\CloudflareDnsService;
use App\Services\PublishArtifactService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'cloudflare.api_token' => 'test-token',
        'cloudflare.zone_id' => 'zone-123',
        'cloudflare.artifact_domain' => 'provisionagents.com',
    ]);
});

test('the agent show page includes the agent artifacts', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $server = Server::factory()->running()->create(['team_id' => $user->currentTeam->id]);
    $agent = Agent::factory()->create([
        'team_id' => $user->currentTeam->id,
        'server_id' => $server->id,
        'name' => 'Luna',
    ]);
    AgentArtifact::factory()->live()->create([
        'agent_id' => $agent->id,
        'team_id' => $agent->team_id,
        'name' => 'Customer Dashboard',
        'source_dir' => 'private/source',
        'start_command' => 'npm run start -- --port 12345',
        'access_token' => 'secret-artifact-token',
        'error_message' => 'private deployment detail',
    ]);

    $this->actingAs($user)->get("/agents/{$agent->id}")
        ->assertInertia(fn ($page) => $page
            ->component('agents/show')
            ->where('artifactsEnabled', true)
            ->has('artifacts', 1)
            ->where('artifacts.0.name', 'Customer Dashboard')
            ->missing('artifacts.0.agent_id')
            ->missing('artifacts.0.team_id')
            ->missing('artifacts.0.source_dir')
            ->missing('artifacts.0.start_command')
            ->missing('artifacts.0.access_token')
            ->missing('artifacts.0.error_message'));
});

test('the agent show page hides artifacts for unsupported harnesses', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $server = Server::factory()->running()->create(['team_id' => $user->currentTeam->id]);
    $agent = Agent::factory()->create([
        'team_id' => $user->currentTeam->id,
        'server_id' => $server->id,
        'harness_type' => HarnessType::Hermes,
    ]);
    AgentArtifact::factory()->live()->create([
        'agent_id' => $agent->id,
        'team_id' => $agent->team_id,
    ]);

    $this->actingAs($user)->get("/agents/{$agent->id}")
        ->assertInertia(fn ($page) => $page
            ->where('artifactsEnabled', false)
            ->has('artifacts', 0));
});

test('a team admin can unpublish an artifact from the dashboard', function () {
    $this->mock(CaddyArtifactService::class)->shouldReceive('syncAgent');
    $this->mock(CloudflareDnsService::class)->shouldReceive('removeAgentRecord')->once();

    $user = User::factory()->withPersonalTeam()->create();
    $agent = Agent::factory()->create(['team_id' => $user->currentTeam->id]);
    $artifact = AgentArtifact::factory()->live()->create([
        'agent_id' => $agent->id, 'team_id' => $agent->team_id,
    ]);

    $this->actingAs($user)
        ->delete("/agents/{$agent->id}/artifacts/{$artifact->id}")
        ->assertRedirect();

    expect(AgentArtifact::find($artifact->id))->toBeNull();
});

test('an artifact from another team cannot be unpublished', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $agent = Agent::factory()->create(['team_id' => $user->currentTeam->id]);
    $foreign = AgentArtifact::factory()->live()->create();

    $this->actingAs($user)
        ->delete("/agents/{$agent->id}/artifacts/{$foreign->id}")
        ->assertNotFound();

    expect(AgentArtifact::find($foreign->id))->not->toBeNull();
});

test('an agent and its artifacts are retained when artifact teardown fails', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $server = Server::factory()->running()->create(['team_id' => $user->currentTeam->id]);
    $agent = Agent::factory()->create([
        'team_id' => $user->currentTeam->id,
        'server_id' => $server->id,
    ]);
    $artifact = AgentArtifact::factory()->live()->create([
        'agent_id' => $agent->id,
        'team_id' => $agent->team_id,
    ]);
    $apiToken = AgentApiToken::createForAgent($agent)['token'];

    $this->mock(PublishArtifactService::class)
        ->shouldReceive('teardownAgent')
        ->once()
        ->withArgs(fn (Agent $candidate): bool => $candidate->is($agent))
        ->andThrow(new RuntimeException('Caddy cleanup failed'));

    $this->withoutExceptionHandling();
    $this->actingAs($user);

    expect(fn () => $this->delete(route('agents.destroy', $agent)))
        ->toThrow(RuntimeException::class, 'Caddy cleanup failed');

    expect(Agent::find($agent->id))->not->toBeNull()
        ->and(Agent::find($agent->id)?->status)->toBe(AgentStatus::Paused)
        ->and(AgentArtifact::find($artifact->id))->not->toBeNull()
        ->and(AgentApiToken::find($apiToken->id))->toBeNull();
});
