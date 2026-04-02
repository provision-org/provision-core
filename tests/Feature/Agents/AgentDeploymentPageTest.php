<?php

use App\Enums\AgentStatus;
use App\Enums\ServerStatus;
use App\Jobs\CreateAgentOnServerJob;
use App\Models\Agent;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Bus;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('provisioning page renders with agent status props', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $agent = Agent::factory()->deploying()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)->get(route('agents.provisioning', $agent));

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('agents/provisioning')
        ->has('agent')
        ->where('agent.id', $agent->id)
        ->where('agent.name', $agent->name)
        ->where('agent.status', AgentStatus::Deploying->value)
    );
});

test('visiting provisioning for pending agent dispatches CreateAgentOnServerJob', function () {
    Bus::fake();
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->pending()->create(['team_id' => $team->id]);

    $this->actingAs($user)->get(route('agents.provisioning', $agent));

    Bus::assertDispatched(CreateAgentOnServerJob::class, function ($job) use ($agent) {
        return $job->agent->id === $agent->id;
    });
});

test('visiting provisioning for pending agent updates status to deploying', function () {
    Bus::fake();
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->pending()->create(['team_id' => $team->id]);

    $this->actingAs($user)->get(route('agents.provisioning', $agent));

    expect($agent->fresh()->status)->toBe(AgentStatus::Deploying);
});

test('refreshing provisioning page does not re-dispatch job', function () {
    Bus::fake();
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->deploying()->create(['team_id' => $team->id]);

    $this->actingAs($user)->get(route('agents.provisioning', $agent));

    Bus::assertNotDispatched(CreateAgentOnServerJob::class);
});

test('provisioning page redirects to show when agent is active', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $agent = Agent::factory()->create(['team_id' => $team->id, 'status' => AgentStatus::Active]);

    $response = $this->actingAs($user)->get(route('agents.provisioning', $agent));

    $response->assertRedirect(route('agents.show', $agent));
});

test('provisioning page renders error state', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $agent = Agent::factory()->create(['team_id' => $team->id, 'status' => AgentStatus::Error]);

    $response = $this->actingAs($user)->get(route('agents.provisioning', $agent));

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('agents/provisioning')
        ->where('agent.status', AgentStatus::Error->value)
    );
});

test('cross-team access to provisioning page is denied', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $foreignTeam = Team::factory()->create();
    $agent = Agent::factory()->deploying()->create(['team_id' => $foreignTeam->id]);

    $response = $this->actingAs($user)->get(route('agents.provisioning', $agent));

    $response->assertNotFound();
});

test('no job dispatched when server is not running', function () {
    Bus::fake();
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    Server::factory()->create(['team_id' => $team->id, 'status' => ServerStatus::Provisioning]);
    $agent = Agent::factory()->pending()->create(['team_id' => $team->id]);

    $this->actingAs($user)->get(route('agents.provisioning', $agent));

    Bus::assertNotDispatched(CreateAgentOnServerJob::class);
    expect($agent->fresh()->status)->toBe(AgentStatus::Pending);
});
