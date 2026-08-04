<?php

use App\Enums\AgentMode;
use App\Enums\ApprovalStatus;
use App\Models\Agent;
use App\Models\Approval;
use App\Models\Server;
use App\Models\Task;
use App\Models\User;

function daemonSetup(): array
{
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;

    $server = Server::factory()->running()->create([
        'team_id' => $team->id,
        'daemon_token' => 'test-daemon-token-123',
    ]);

    $agent = Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'agent_mode' => AgentMode::Workforce,
    ]);

    return [$team, $server, $agent];
}

test('work queue returns tasks for workforce agents on server', function () {
    [$team, $server, $agent] = daemonSetup();

    Task::factory()->count(2)->create([
        'team_id' => $team->id,
        'agent_id' => $agent->id,
        'status' => 'todo',
    ]);

    // Task on different agent should not appear
    $otherAgent = Agent::factory()->create([
        'team_id' => $team->id,
        'agent_mode' => AgentMode::Channel,
    ]);
    Task::factory()->create([
        'team_id' => $team->id,
        'agent_id' => $otherAgent->id,
        'status' => 'todo',
    ]);

    $response = $this->getJson('/api/daemon/test-daemon-token-123/work-queue');

    $response->assertOk();
    $response->assertJsonCount(2, 'tasks');
});

test('work queue excludes checked-out tasks', function () {
    [$team, $server, $agent] = daemonSetup();

    Task::factory()->create([
        'team_id' => $team->id,
        'agent_id' => $agent->id,
        'status' => 'todo',
    ]);

    Task::factory()->create([
        'team_id' => $team->id,
        'agent_id' => $agent->id,
        'status' => 'todo',
        'checked_out_by_run' => 'run-1',
        'checkout_expires_at' => now()->addHour(),
    ]);

    $response = $this->getJson('/api/daemon/test-daemon-token-123/work-queue');

    $response->assertOk();
    $response->assertJsonCount(1, 'tasks');
});

test('checkout task succeeds for available task', function () {
    [$team, $server, $agent] = daemonSetup();

    $task = Task::factory()->create([
        'team_id' => $team->id,
        'agent_id' => $agent->id,
        'status' => 'todo',
    ]);

    $response = $this->postJson("/api/daemon/test-daemon-token-123/tasks/{$task->id}/checkout", [
        'daemon_run_id' => 'run-abc',
    ]);

    $response->assertOk();

    $task->refresh();
    expect($task->checked_out_by_run)->toBe('run-abc');
    expect($task->status)->toBe('in_progress');
});

test('server-scoped bearer auth supports task routes with route model binding', function () {
    [$team, $server, $agent] = daemonSetup();
    $task = Task::factory()->create([
        'team_id' => $team->id,
        'agent_id' => $agent->id,
        'status' => 'todo',
    ]);

    $this->withToken('test-daemon-token-123')
        ->postJson("/api/daemon/servers/{$server->id}/tasks/{$task->id}/checkout", [
            'daemon_run_id' => 'header-run-abc',
        ])
        ->assertSuccessful();

    expect($task->fresh()->checked_out_by_run)->toBe('header-run-abc');
});

test('checkout task returns 409 if already checked out', function () {
    [$team, $server, $agent] = daemonSetup();

    $task = Task::factory()->create([
        'team_id' => $team->id,
        'agent_id' => $agent->id,
        'status' => 'todo',
        'checked_out_by_run' => 'other-run',
        'checkout_expires_at' => now()->addHour(),
    ]);

    $response = $this->postJson("/api/daemon/test-daemon-token-123/tasks/{$task->id}/checkout", [
        'daemon_run_id' => 'run-abc',
    ]);

    $response->assertStatus(409);
});

test('report result updates task and creates usage event', function () {
    [$team, $server, $agent] = daemonSetup();

    $task = Task::factory()->inProgress()->create([
        'team_id' => $team->id,
        'agent_id' => $agent->id,
        'checked_out_by_run' => 'run-1',
    ]);

    $response = $this->postJson("/api/daemon/test-daemon-token-123/tasks/{$task->id}/result", [
        'daemon_run_id' => 'run-1',
        'status' => 'done',
        'result_summary' => 'Task completed successfully.',
        'tokens_input' => 5000,
        'tokens_output' => 1000,
        'model' => 'anthropic/claude-haiku-4-5',
    ]);

    $response->assertOk();

    $task->refresh();
    expect($task->status)->toBe('done');
    expect($task->completed_at)->not->toBeNull();
    expect($task->tokens_input)->toBe(5000);

    $this->assertDatabaseHas('usage_events', [
        'team_id' => $team->id,
        'agent_id' => $agent->id,
        'task_id' => $task->id,
        'input_tokens' => 5000,
    ]);
});

test('report result creates delegated sub-tasks', function () {
    [$team, $server, $agent] = daemonSetup();

    $agent->update(['delegation_enabled' => true]);

    $directReport = Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'agent_mode' => AgentMode::Workforce,
        'name' => 'Research Bot',
        'reports_to' => $agent->id,
    ]);

    $task = Task::factory()->inProgress()->create([
        'team_id' => $team->id,
        'agent_id' => $agent->id,
    ]);

    $response = $this->postJson("/api/daemon/test-daemon-token-123/tasks/{$task->id}/result", [
        'daemon_run_id' => 'run-1',
        'status' => 'in_progress',
        'delegations' => [
            [
                'agent_name' => 'Research Bot',
                'title' => 'Gather market data',
                'description' => 'Find competitor pricing',
            ],
        ],
    ]);

    $response->assertOk();

    $this->assertDatabaseHas('tasks', [
        'agent_id' => $directReport->id,
        'title' => 'Gather market data',
        'parent_task_id' => $task->id,
        'delegated_by' => $agent->id,
    ]);
});

test('report result creates approval requests and blocks task', function () {
    [$team, $server, $agent] = daemonSetup();

    $task = Task::factory()->inProgress()->create([
        'team_id' => $team->id,
        'agent_id' => $agent->id,
    ]);

    $response = $this->postJson("/api/daemon/test-daemon-token-123/tasks/{$task->id}/result", [
        'daemon_run_id' => 'run-1',
        'status' => 'in_progress',
        'approval_requests' => [
            [
                'type' => 'external_action',
                'title' => 'Send email to client',
                'payload' => ['recipient' => 'client@example.com'],
            ],
        ],
    ]);

    $response->assertOk();

    $this->assertDatabaseHas('approvals', [
        'team_id' => $team->id,
        'requesting_agent_id' => $agent->id,
        'title' => 'Send email to client',
        'status' => ApprovalStatus::Pending->value,
        'linked_task_id' => $task->id,
    ]);

    $task->refresh();
    expect($task->status)->toBe('blocked');
});

test('release task works with correct run id', function () {
    [$team, $server, $agent] = daemonSetup();

    $task = Task::factory()->inProgress()->create([
        'team_id' => $team->id,
        'agent_id' => $agent->id,
        'checked_out_by_run' => 'run-xyz',
        'checkout_expires_at' => now()->addHour(),
    ]);

    $response = $this->postJson("/api/daemon/test-daemon-token-123/tasks/{$task->id}/release", [
        'daemon_run_id' => 'run-xyz',
    ]);

    $response->assertOk();

    $task->refresh();
    expect($task->checked_out_by_run)->toBeNull();
});

test('resolved approvals returns recently resolved items', function () {
    [$team, $server, $agent] = daemonSetup();

    Approval::factory()->approved()->create([
        'team_id' => $team->id,
        'requesting_agent_id' => $agent->id,
        'reviewed_at' => now()->subHours(2),
    ]);

    // Old approval should not appear
    Approval::factory()->approved()->create([
        'team_id' => $team->id,
        'requesting_agent_id' => $agent->id,
        'reviewed_at' => now()->subDays(2),
    ]);

    $response = $this->getJson('/api/daemon/test-daemon-token-123/resolved-approvals');

    $response->assertOk();
    $response->assertJsonCount(1, 'approvals');
});

test('usage event is recorded', function () {
    [$team, $server, $agent] = daemonSetup();

    $response = $this->postJson('/api/daemon/test-daemon-token-123/usage-events', [
        'agent_id' => $agent->id,
        'model' => 'anthropic/claude-haiku-4-5',
        'input_tokens' => 1000,
        'output_tokens' => 200,
    ]);

    $response->assertOk();

    $this->assertDatabaseHas('usage_events', [
        'agent_id' => $agent->id,
        'input_tokens' => 1000,
        'output_tokens' => 200,
    ]);
});

test('heartbeat persists daemon health version capabilities and active runs', function () {
    [$team, $server, $agent] = daemonSetup();

    $response = $this->postJson('/api/daemon/test-daemon-token-123/heartbeat', [
        'version' => '0.4.0',
        'capabilities' => ['chat-relay-v1'],
        'active_runs' => ['run-one', 'run-two'],
    ]);

    $response->assertOk()
        ->assertJsonPath('desired_version', config('provision.provisiond_version'));

    $server->refresh();
    expect($server->last_health_check)->not->toBeNull()
        ->and($server->daemon_version)->toBe('0.4.0')
        ->and($server->daemon_capabilities)->toBe(['chat-relay-v1'])
        ->and($server->daemon_active_runs)->toBe(['run-one', 'run-two']);
});

test('invalid daemon token returns 401', function () {
    $response = $this->getJson('/api/daemon/invalid-token/work-queue');

    $response->assertUnauthorized();
});
