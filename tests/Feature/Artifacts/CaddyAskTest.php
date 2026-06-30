<?php

use App\Models\Agent;
use App\Models\AgentArtifact;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => config(['cloudflare.artifact_domain' => 'provisionagents.com']));

test('the ask endpoint allows a host with a live artifact', function () {
    $agent = Agent::factory()->create(['name' => 'Luna']);
    AgentArtifact::factory()->live()->create([
        'agent_id' => $agent->id,
        'team_id' => $agent->team_id,
    ]);

    $this->get("/api/caddy/ask?domain={$agent->slug}.provisionagents.com")
        ->assertOk();
});

test('the ask endpoint rejects a host with no live artifact', function () {
    $agent = Agent::factory()->create(['name' => 'Luna']);
    AgentArtifact::factory()->pending()->create([
        'agent_id' => $agent->id,
        'team_id' => $agent->team_id,
    ]);

    $this->get("/api/caddy/ask?domain={$agent->slug}.provisionagents.com")
        ->assertNotFound();
});

test('the ask endpoint rejects an unknown subdomain', function () {
    $this->get('/api/caddy/ask?domain=ghost.provisionagents.com')->assertNotFound();
});

test('the ask endpoint rejects hosts outside the artifact domain', function () {
    $agent = Agent::factory()->create(['name' => 'Luna']);
    AgentArtifact::factory()->live()->create([
        'agent_id' => $agent->id,
        'team_id' => $agent->team_id,
    ]);

    $this->get('/api/caddy/ask?domain=evil.com')->assertNotFound();
});
