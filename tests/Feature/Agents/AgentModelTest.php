<?php

use App\Enums\AgentRole;
use App\Enums\AgentStatus;
use App\Models\Agent;
use App\Models\AgentEmailConnection;
use App\Models\AgentSlackConnection;
use App\Models\Server;
use App\Models\Team;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('agent belongs to a team', function () {
    $team = Team::factory()->create();
    $agent = Agent::factory()->create(['team_id' => $team->id]);

    expect($agent->team->id)->toBe($team->id);
});

test('agent belongs to a server (nullable)', function () {
    $agent = Agent::factory()->create(['server_id' => null]);

    expect($agent->server)->toBeNull();

    $server = Server::factory()->create(['team_id' => $agent->team_id]);
    $agent->update(['server_id' => $server->id]);

    expect($agent->fresh()->server->id)->toBe($server->id);
});

test('agent has one slack connection', function () {
    $agent = Agent::factory()->create();
    $slack = AgentSlackConnection::factory()->create(['agent_id' => $agent->id]);

    expect($agent->fresh()->slackConnection->id)->toBe($slack->id);
});

test('agent has one email connection', function () {
    $agent = Agent::factory()->create();
    $email = AgentEmailConnection::factory()->create(['agent_id' => $agent->id]);

    expect($agent->fresh()->emailConnection->id)->toBe($email->id);
});

test('factory creates agent with default state', function () {
    $agent = Agent::factory()->create();

    expect($agent->name)->not->toBeEmpty()
        ->and($agent->role)->toBe(AgentRole::Custom)
        ->and($agent->status)->toBe(AgentStatus::Active)
        ->and($agent->model_primary)->toBe('claude-opus-4-6');
});

test('factory bdr state sets role and system prompt', function () {
    $agent = Agent::factory()->bdr()->create();

    expect($agent->role)->toBe(AgentRole::Bdr)
        ->and($agent->system_prompt)->not->toBeNull();
});

test('factory executive assistant state sets role and system prompt', function () {
    $agent = Agent::factory()->executiveAssistant()->create();

    expect($agent->role)->toBe(AgentRole::ExecutiveAssistant)
        ->and($agent->system_prompt)->not->toBeNull();
});

test('factory frontend developer state sets role and system prompt', function () {
    $agent = Agent::factory()->frontendDeveloper()->create();

    expect($agent->role)->toBe(AgentRole::FrontendDeveloper)
        ->and($agent->system_prompt)->not->toBeNull();
});

test('factory researcher state sets role and system prompt', function () {
    $agent = Agent::factory()->researcher()->create();

    expect($agent->role)->toBe(AgentRole::Researcher)
        ->and($agent->system_prompt)->not->toBeNull();
});

test('status is cast to AgentStatus enum', function () {
    $agent = Agent::factory()->create(['status' => 'paused']);

    expect($agent->status)->toBe(AgentStatus::Paused);
});

test('role is cast to AgentRole enum', function () {
    $agent = Agent::factory()->create(['role' => 'bdr']);

    expect($agent->role)->toBe(AgentRole::Bdr);
});

test('model_fallbacks is cast to array', function () {
    $fallbacks = ['claude-opus-4-5', 'claude-opus-4-6'];
    $agent = Agent::factory()->create(['model_fallbacks' => $fallbacks]);

    expect($agent->model_fallbacks)->toBe($fallbacks);
});

test('config_snapshot is cast to array', function () {
    $snapshot = ['agents' => ['list' => []]];
    $agent = Agent::factory()->create(['config_snapshot' => $snapshot]);

    expect($agent->config_snapshot)->toBe($snapshot);
});
