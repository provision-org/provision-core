<?php

use App\Enums\AgentMode;
use App\Models\Agent;
use App\Models\Server;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;

it('auto-generates handle from name on creation', function () {
    $team = Team::factory()->create();

    $agent = Agent::factory()->create([
        'team_id' => $team->id,
        'name' => 'Radar',
    ]);

    expect($agent->handle)->toBe('radar');
});

it('slugifies names with spaces and special characters', function () {
    $team = Team::factory()->create();

    $agent = Agent::factory()->create([
        'team_id' => $team->id,
        'name' => 'Dr. Analytics Pro',
    ]);

    expect($agent->handle)->toBe('dr-analytics-pro');
});

it('appends suffix on handle collision within same team', function () {
    $team = Team::factory()->create();

    $first = Agent::factory()->create([
        'team_id' => $team->id,
        'name' => 'Scout',
    ]);

    $second = Agent::factory()->create([
        'team_id' => $team->id,
        'name' => 'Scout',
    ]);

    expect($first->handle)->toBe('scout');
    expect($second->handle)->toBe('scout-2');
});

it('allows same handle on different teams', function () {
    $teamA = Team::factory()->create();
    $teamB = Team::factory()->create();

    $agentA = Agent::factory()->create([
        'team_id' => $teamA->id,
        'name' => 'Radar',
    ]);

    $agentB = Agent::factory()->create([
        'team_id' => $teamB->id,
        'name' => 'Radar',
    ]);

    expect($agentA->handle)->toBe('radar');
    expect($agentB->handle)->toBe('radar');
});

it('does not overwrite explicitly set handle', function () {
    $team = Team::factory()->create();

    $agent = Agent::factory()->create([
        'team_id' => $team->id,
        'name' => 'Radar',
        'handle' => 'custom-handle',
    ]);

    expect($agent->handle)->toBe('custom-handle');
});

it('includes handle in daemon work queue response', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;

    $server = Server::factory()->running()->create([
        'team_id' => $team->id,
        'daemon_token' => 'handle-test-token',
    ]);

    $agent = Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'name' => 'Radar',
        'agent_mode' => AgentMode::Workforce,
    ]);

    Task::factory()->create([
        'team_id' => $team->id,
        'agent_id' => $agent->id,
        'status' => 'todo',
    ]);

    $response = $this->getJson('/api/daemon/handle-test-token/work-queue');

    $response->assertOk();
    $response->assertJsonPath('tasks.0.agent.handle', 'radar');
});

it('resolves delegation by handle', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;

    $server = Server::factory()->running()->create([
        'team_id' => $team->id,
        'daemon_token' => 'delegation-handle-token',
    ]);

    $manager = Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'agent_mode' => AgentMode::Workforce,
        'name' => 'Manager Bot',
        'delegation_enabled' => true,
    ]);

    $report = Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'agent_mode' => AgentMode::Workforce,
        'name' => 'Research Bot',
        'reports_to' => $manager->id,
    ]);

    $task = Task::factory()->inProgress()->create([
        'team_id' => $team->id,
        'agent_id' => $manager->id,
    ]);

    $response = $this->postJson("/api/daemon/delegation-handle-token/tasks/{$task->id}/result", [
        'daemon_run_id' => 'run-handle-1',
        'status' => 'in_progress',
        'delegations' => [
            [
                'agent_name' => 'research-bot',
                'title' => 'Gather market data',
                'description' => 'Find competitor pricing',
            ],
        ],
    ]);

    $response->assertOk();

    $this->assertDatabaseHas('tasks', [
        'agent_id' => $report->id,
        'title' => 'Gather market data',
        'parent_task_id' => $task->id,
        'delegated_by' => $manager->id,
    ]);
});

it('falls back to name for delegation when handle does not match', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;

    $server = Server::factory()->running()->create([
        'team_id' => $team->id,
        'daemon_token' => 'delegation-name-token',
    ]);

    $manager = Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'agent_mode' => AgentMode::Workforce,
        'name' => 'Manager Bot',
        'delegation_enabled' => true,
    ]);

    $report = Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'agent_mode' => AgentMode::Workforce,
        'name' => 'Research Bot',
        'reports_to' => $manager->id,
    ]);

    $task = Task::factory()->inProgress()->create([
        'team_id' => $team->id,
        'agent_id' => $manager->id,
    ]);

    $response = $this->postJson("/api/daemon/delegation-name-token/tasks/{$task->id}/result", [
        'daemon_run_id' => 'run-name-1',
        'status' => 'in_progress',
        'delegations' => [
            [
                'agent_name' => 'Research Bot',
                'title' => 'Analyze competitors',
                'description' => 'Review pricing pages',
            ],
        ],
    ]);

    $response->assertOk();

    $this->assertDatabaseHas('tasks', [
        'agent_id' => $report->id,
        'title' => 'Analyze competitors',
        'parent_task_id' => $task->id,
        'delegated_by' => $manager->id,
    ]);
});
