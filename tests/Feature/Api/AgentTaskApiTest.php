<?php

use App\Enums\AgentMode;
use App\Enums\AgentStatus;
use App\Events\TaskStatusChangedEvent;
use App\Jobs\NotifyDelegatorAboutTaskCompletionJob;
use App\Listeners\NotifyDelegatorOnTaskCompletion;
use App\Models\Agent;
use App\Models\AgentApiToken;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

function createAgentWithToken(array $agentAttributes = []): array
{
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;

    subscribeTeam($team);

    $agent = Agent::factory()->create(array_merge([
        'team_id' => $team->id,
        'status' => AgentStatus::Active,
    ], $agentAttributes));

    $result = AgentApiToken::createForAgent($agent);

    return ['agent' => $agent, 'team' => $team, 'token' => $result['plaintext']];
}

// --- Authentication ---

test('unauthenticated requests return 401', function () {
    $this->getJson('/api/tasks')->assertStatus(401);
    $this->postJson('/api/tasks', ['title' => 'Test'])->assertStatus(401);
});

test('invalid token returns 401', function () {
    $this->getJson('/api/tasks', ['Authorization' => 'Bearer prov_invalidtoken'])
        ->assertStatus(401);
});

test('valid token returns 200', function () {
    ['token' => $token] = createAgentWithToken();

    $this->getJson('/api/tasks', ['Authorization' => "Bearer {$token}"])
        ->assertSuccessful();
});

// --- Task Creation ---

test('agent can create a self-assigned task', function () {
    ['agent' => $agent, 'token' => $token] = createAgentWithToken();

    $response = $this->postJson('/api/tasks', [
        'title' => 'Research competitors',
        'description' => 'Find top 5 competitors',
        'priority' => 'high',
    ], ['Authorization' => "Bearer {$token}"]);

    $response->assertStatus(201);

    $task = Task::first();
    expect($task->agent_id)->toBe($agent->id)
        ->and($task->created_by_type)->toBe('agent')
        ->and($task->created_by_id)->toBe($agent->id)
        ->and($task->status)->toBe('in_progress')
        ->and($task->delegated_by)->toBeNull();
});

test('agent can delegate to workforce agent via handle - status becomes todo', function () {
    Queue::fake();

    ['agent' => $luna, 'team' => $team, 'token' => $token] = createAgentWithToken([
        'name' => 'Luna',
        'handle' => 'luna',
        'agent_mode' => AgentMode::Channel,
        'delegation_enabled' => true,
    ]);

    $max = Agent::factory()->create([
        'team_id' => $team->id,
        'name' => 'Max',
        'handle' => 'max',
        'agent_mode' => AgentMode::Workforce,
        'status' => AgentStatus::Active,
    ]);

    $response = $this->postJson('/api/tasks', [
        'title' => 'Check ahrefs report',
        'assign_to' => 'max',
    ], ['Authorization' => "Bearer {$token}"]);

    $response->assertStatus(201);

    $task = Task::first();
    expect($task->agent_id)->toBe($max->id)
        ->and($task->status)->toBe('todo')
        ->and($task->delegated_by)->toBe($luna->id)
        ->and((int) $task->request_depth)->toBe(0)
        ->and($task->created_by_id)->toBe($luna->id);
});

test('agent can delegate using @handle prefix', function () {
    Queue::fake();

    ['agent' => $luna, 'team' => $team, 'token' => $token] = createAgentWithToken([
        'name' => 'Luna',
        'handle' => 'luna',
        'agent_mode' => AgentMode::Channel,
        'delegation_enabled' => true,
    ]);

    $max = Agent::factory()->create([
        'team_id' => $team->id,
        'name' => 'Max',
        'handle' => 'max',
        'agent_mode' => AgentMode::Workforce,
        'status' => AgentStatus::Active,
    ]);

    $response = $this->postJson('/api/tasks', [
        'title' => 'Check SEO metrics',
        'assign_to' => '@max',
    ], ['Authorization' => "Bearer {$token}"]);

    $response->assertStatus(201);

    $task = Task::first();
    expect($task->agent_id)->toBe($max->id)
        ->and($task->status)->toBe('todo');
});

test('delegation fails when agent has delegation_enabled=false', function () {
    ['team' => $team, 'token' => $token] = createAgentWithToken([
        'delegation_enabled' => false,
    ]);

    Agent::factory()->create([
        'team_id' => $team->id,
        'name' => 'Max',
        'handle' => 'max',
        'agent_mode' => AgentMode::Workforce,
        'status' => AgentStatus::Active,
    ]);

    $this->postJson('/api/tasks', [
        'title' => 'Delegate this',
        'assign_to' => 'max',
    ], ['Authorization' => "Bearer {$token}"])
        ->assertStatus(403);
});

test('delegating to channel agent sets status up_next', function () {
    Queue::fake();

    ['team' => $team, 'token' => $token] = createAgentWithToken([
        'name' => 'Luna',
        'agent_mode' => AgentMode::Channel,
        'delegation_enabled' => true,
    ]);

    $other = Agent::factory()->create([
        'team_id' => $team->id,
        'name' => 'Neo',
        'handle' => 'neo',
        'agent_mode' => AgentMode::Channel,
        'status' => AgentStatus::Active,
    ]);

    $this->postJson('/api/tasks', [
        'title' => 'Ask Neo about leads',
        'assign_to' => 'neo',
    ], ['Authorization' => "Bearer {$token}"])
        ->assertStatus(201);

    $task = Task::first();
    expect($task->status)->toBe('up_next');
});

// --- Event Dispatch ---

test('complete fires TaskStatusChangedEvent', function () {
    Event::fake([TaskStatusChangedEvent::class]);

    ['agent' => $agent, 'team' => $team, 'token' => $token] = createAgentWithToken();

    $task = Task::factory()->create([
        'team_id' => $team->id,
        'agent_id' => $agent->id,
        'status' => 'in_progress',
    ]);

    $this->patchJson("/api/tasks/{$task->id}/complete", [], [
        'Authorization' => "Bearer {$token}",
    ])->assertSuccessful();

    Event::assertDispatched(TaskStatusChangedEvent::class, function ($event) {
        return $event->oldStatus === 'in_progress' && $event->newStatus === 'done';
    });
});

test('block fires TaskStatusChangedEvent', function () {
    Event::fake([TaskStatusChangedEvent::class]);

    ['agent' => $agent, 'team' => $team, 'token' => $token] = createAgentWithToken();

    $task = Task::factory()->create([
        'team_id' => $team->id,
        'agent_id' => $agent->id,
        'status' => 'in_progress',
    ]);

    $this->patchJson("/api/tasks/{$task->id}/block", [
        'reason' => 'Need credentials',
    ], ['Authorization' => "Bearer {$token}"])
        ->assertSuccessful();

    Event::assertDispatched(TaskStatusChangedEvent::class, function ($event) {
        return $event->newStatus === 'blocked';
    });
});

test('update with status change fires TaskStatusChangedEvent', function () {
    Event::fake([TaskStatusChangedEvent::class]);

    ['agent' => $agent, 'team' => $team, 'token' => $token] = createAgentWithToken();

    $task = Task::factory()->create([
        'team_id' => $team->id,
        'agent_id' => $agent->id,
        'status' => 'in_progress',
    ]);

    $this->patchJson("/api/tasks/{$task->id}", [
        'status' => 'done',
    ], ['Authorization' => "Bearer {$token}"])
        ->assertSuccessful();

    Event::assertDispatched(TaskStatusChangedEvent::class, function ($event) {
        return $event->newStatus === 'done';
    });
});

// --- Completion Listener ---

test('listener dispatches notification job when delegated task completes', function () {
    Queue::fake();

    $team = Team::factory()->create();

    $luna = Agent::factory()->create([
        'team_id' => $team->id,
        'name' => 'Luna',
        'agent_mode' => AgentMode::Channel,
        'status' => AgentStatus::Active,
    ]);

    $max = Agent::factory()->create([
        'team_id' => $team->id,
        'name' => 'Max',
        'agent_mode' => AgentMode::Workforce,
        'status' => AgentStatus::Active,
    ]);

    $task = Task::factory()->create([
        'team_id' => $team->id,
        'agent_id' => $max->id,
        'delegated_by' => $luna->id,
        'status' => 'done',
    ]);

    $listener = new NotifyDelegatorOnTaskCompletion;
    $listener->handle(new TaskStatusChangedEvent($task->load('agent'), 'in_progress', 'done'));

    Queue::assertPushed(NotifyDelegatorAboutTaskCompletionJob::class, function ($job) use ($luna) {
        return $job->agent->id === $luna->id && $job->newStatus === 'done';
    });
});

test('listener skips non-delegated tasks', function () {
    Queue::fake();

    $team = Team::factory()->create();

    $agent = Agent::factory()->create([
        'team_id' => $team->id,
        'status' => AgentStatus::Active,
    ]);

    $task = Task::factory()->create([
        'team_id' => $team->id,
        'agent_id' => $agent->id,
        'delegated_by' => null,
        'status' => 'done',
    ]);

    $listener = new NotifyDelegatorOnTaskCompletion;
    $listener->handle(new TaskStatusChangedEvent($task->load('agent'), 'in_progress', 'done'));

    Queue::assertNotPushed(NotifyDelegatorAboutTaskCompletionJob::class);
});

test('listener skips non-terminal status changes', function () {
    Queue::fake();

    $team = Team::factory()->create();

    $luna = Agent::factory()->create([
        'team_id' => $team->id,
        'status' => AgentStatus::Active,
    ]);

    $max = Agent::factory()->create([
        'team_id' => $team->id,
        'status' => AgentStatus::Active,
    ]);

    $task = Task::factory()->create([
        'team_id' => $team->id,
        'agent_id' => $max->id,
        'delegated_by' => $luna->id,
        'status' => 'in_progress',
    ]);

    $listener = new NotifyDelegatorOnTaskCompletion;
    $listener->handle(new TaskStatusChangedEvent($task->load('agent'), 'todo', 'in_progress'));

    Queue::assertNotPushed(NotifyDelegatorAboutTaskCompletionJob::class);
});

test('listener fires on failed and blocked statuses', function () {
    Queue::fake();

    $team = Team::factory()->create();

    $luna = Agent::factory()->create([
        'team_id' => $team->id,
        'status' => AgentStatus::Active,
    ]);

    $max = Agent::factory()->create([
        'team_id' => $team->id,
        'status' => AgentStatus::Active,
    ]);

    $task = Task::factory()->create([
        'team_id' => $team->id,
        'agent_id' => $max->id,
        'delegated_by' => $luna->id,
        'status' => 'failed',
    ]);

    $listener = new NotifyDelegatorOnTaskCompletion;

    $listener->handle(new TaskStatusChangedEvent($task->load('agent'), 'in_progress', 'failed'));
    $listener->handle(new TaskStatusChangedEvent($task->load('agent'), 'in_progress', 'blocked'));

    Queue::assertPushed(NotifyDelegatorAboutTaskCompletionJob::class, 2);
});
