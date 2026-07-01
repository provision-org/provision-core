<?php

use App\Models\Agent;
use App\Models\AgentArtifact;
use App\Models\User;
use App\Services\CaddyArtifactService;
use App\Services\CloudflareDnsService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the agent show page includes the agent artifacts', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $agent = Agent::factory()->create(['team_id' => $user->currentTeam->id, 'name' => 'Luna']);
    AgentArtifact::factory()->live()->create([
        'agent_id' => $agent->id, 'team_id' => $agent->team_id, 'name' => 'Customer Dashboard',
    ]);

    $this->actingAs($user)->get("/agents/{$agent->id}")
        ->assertInertia(fn ($page) => $page
            ->component('agents/show')
            ->has('artifacts', 1)
            ->where('artifacts.0.name', 'Customer Dashboard'));
});

test('a team admin can unpublish an artifact from the dashboard', function () {
    $this->mock(CaddyArtifactService::class)->shouldReceive('syncAgent');
    $this->mock(CloudflareDnsService::class)->shouldReceive('isConfigured')->andReturnFalse();

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
