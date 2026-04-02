<?php

use App\Enums\AgentStatus;
use App\Models\Agent;
use App\Models\AgentDailyStat;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Bus;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('it returns daily delta data for an agent', function () {
    Bus::fake();
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'status' => AgentStatus::Active,
    ]);

    // Day 1 cumulative snapshot
    AgentDailyStat::query()->create([
        'agent_id' => $agent->id,
        'date' => now()->subDays(2)->toDateString(),
        'cumulative_tokens_input' => 1000,
        'cumulative_tokens_output' => 200,
        'cumulative_messages' => 10,
        'cumulative_sessions' => 3,
    ]);

    // Day 2 cumulative snapshot
    AgentDailyStat::query()->create([
        'agent_id' => $agent->id,
        'date' => now()->subDays(1)->toDateString(),
        'cumulative_tokens_input' => 2500,
        'cumulative_tokens_output' => 500,
        'cumulative_messages' => 18,
        'cumulative_sessions' => 5,
    ]);

    // Day 3 cumulative snapshot
    AgentDailyStat::query()->create([
        'agent_id' => $agent->id,
        'date' => now()->toDateString(),
        'cumulative_tokens_input' => 4000,
        'cumulative_tokens_output' => 900,
        'cumulative_messages' => 25,
        'cumulative_sessions' => 8,
    ]);

    $response = $this->actingAs($user)->getJson(route('agents.usage-chart', $agent));

    $response->assertOk();
    $data = $response->json();

    expect($data)->toHaveCount(3);

    // First day delta = cumulative - 0 (no baseline)
    expect($data[0]['tokens_input'])->toBe(1000);
    expect($data[0]['tokens_output'])->toBe(200);

    // Second day delta
    expect($data[1]['tokens_input'])->toBe(1500);
    expect($data[1]['tokens_output'])->toBe(300);

    // Third day delta
    expect($data[2]['tokens_input'])->toBe(1500);
    expect($data[2]['tokens_output'])->toBe(400);
});

test('it returns 404 for another team agent', function () {
    Bus::fake();
    $user = User::factory()->withPersonalTeam()->create();
    $otherTeam = Team::factory()->create();
    $server = Server::factory()->running()->create(['team_id' => $otherTeam->id]);
    $agent = Agent::factory()->create([
        'team_id' => $otherTeam->id,
        'server_id' => $server->id,
        'status' => AgentStatus::Active,
    ]);

    $response = $this->actingAs($user)->getJson(route('agents.usage-chart', $agent));

    $response->assertNotFound();
});

test('it returns empty array when no daily stats exist', function () {
    Bus::fake();
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'status' => AgentStatus::Active,
    ]);

    $response = $this->actingAs($user)->getJson(route('agents.usage-chart', $agent));

    $response->assertOk();
    expect($response->json())->toBeEmpty();
});
