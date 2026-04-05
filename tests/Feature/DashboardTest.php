<?php

use App\Models\Agent;
use App\Models\AgentDailyStat;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can access dashboard', function () {
    $user = User::factory()->create([
        'profile_completed_at' => now(),
        'activated_at' => now(),
    ]);
    $user->ownedTeams()->create([
        'name' => 'Test Team',
        'personal_team' => true,
    ]);
    $user->switchTeam($user->ownedTeams()->first());

    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

function createDashboardTeam(): Team
{
    $user = User::factory()->create([
        'profile_completed_at' => now(),
        'activated_at' => now(),
    ]);
    $team = Team::factory()->subscribed()->create(['user_id' => $user->id]);
    $user->switchTeam($team);

    return $team;
}

test('dashboard includes aggregated token stats', function () {
    $team = createDashboardTeam();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);

    Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'stats_tokens_input' => 1000,
        'stats_tokens_output' => 500,
        'stats_total_sessions' => 5,
        'stats_total_messages' => 20,
    ]);

    Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'stats_tokens_input' => 2000,
        'stats_tokens_output' => 1000,
        'stats_total_sessions' => 10,
        'stats_total_messages' => 30,
    ]);

    $response = $this->actingAs($team->owner)->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('dashboard')
        ->has('tokenStats')
        ->where('tokenStats.total_input', 3000)
        ->where('tokenStats.total_output', 1500)
        ->where('tokenStats.total_sessions', 15)
        ->where('tokenStats.total_messages', 50)
    );
});

test('usage chart returns daily data aggregated across agents', function () {
    $team = createDashboardTeam();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);

    $agent1 = Agent::factory()->create(['team_id' => $team->id, 'server_id' => $server->id]);
    $agent2 = Agent::factory()->create(['team_id' => $team->id, 'server_id' => $server->id]);

    $today = now()->toDateString();

    AgentDailyStat::create([
        'agent_id' => $agent1->id,
        'date' => $today,
        'cumulative_tokens_input' => 100,
        'cumulative_tokens_output' => 50,
        'cumulative_messages' => 5,
        'cumulative_sessions' => 2,
    ]);

    AgentDailyStat::create([
        'agent_id' => $agent2->id,
        'date' => $today,
        'cumulative_tokens_input' => 200,
        'cumulative_tokens_output' => 100,
        'cumulative_messages' => 10,
        'cumulative_sessions' => 3,
    ]);

    $response = $this->actingAs($team->owner)->getJson(route('dashboard.usage-chart', ['days' => 30]));

    $response->assertOk();

    $data = $response->json();
    expect($data)->toHaveCount(1);
    expect($data[0]['date'])->toBe($today);
    expect($data[0]['tokens_input'])->toBe(300);
    expect($data[0]['tokens_output'])->toBe(150);
});

test('usage chart returns empty array when no agents exist', function () {
    $team = createDashboardTeam();

    $response = $this->actingAs($team->owner)->getJson(route('dashboard.usage-chart'));

    $response->assertOk()->assertJson([]);
});
