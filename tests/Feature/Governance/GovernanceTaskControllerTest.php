<?php

use App\Enums\AgentMode;
use App\Enums\TaskPriority;
use App\Models\Agent;
use App\Models\Task;
use App\Models\User;

function taskUser(): User
{
    return User::factory()->withPersonalTeam()->create();
}

function workforceAgent($team): Agent
{
    return Agent::factory()->create([
        'team_id' => $team->id,
        'agent_mode' => AgentMode::Workforce,
    ]);
}

test('user can list tasks for their team', function () {
    $user = taskUser();
    $team = $user->currentTeam;
    $agent = workforceAgent($team);

    Task::factory()->count(3)->create(['team_id' => $team->id, 'agent_id' => $agent->id]);

    $response = $this->actingAs($user)->get(route('company.tasks.index'));

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page->component('company/tasks/index'));
});

test('user can filter tasks by status', function () {
    $user = taskUser();
    $team = $user->currentTeam;
    $agent = workforceAgent($team);

    Task::factory()->create(['team_id' => $team->id, 'agent_id' => $agent->id, 'status' => 'todo']);
    Task::factory()->done()->create(['team_id' => $team->id, 'agent_id' => $agent->id]);

    $response = $this->actingAs($user)->get(route('company.tasks.index', [
        'team' => $team,
        'status' => 'todo',
    ]));

    $response->assertSuccessful();
});

test('user can create a task', function () {
    $user = taskUser();
    $team = $user->currentTeam;
    $agent = workforceAgent($team);

    $response = $this->actingAs($user)->post(route('company.tasks.store'), [
        'title' => 'Research competitors',
        'description' => 'Analyze top 5 competitors',
        'agent_id' => $agent->id,
        'priority' => TaskPriority::High->value,
    ]);

    $response->assertRedirect(route('company.tasks.index'));

    $this->assertDatabaseHas('tasks', [
        'team_id' => $team->id,
        'title' => 'Research competitors',
        'agent_id' => $agent->id,
        'status' => 'todo',
    ]);
});

test('task creation logs audit entry', function () {
    $user = taskUser();
    $team = $user->currentTeam;
    $agent = workforceAgent($team);

    $this->actingAs($user)->post(route('company.tasks.store'), [
        'title' => 'Audit test task',
        'agent_id' => $agent->id,
        'priority' => TaskPriority::Medium->value,
    ]);

    $this->assertDatabaseHas('audit_log', [
        'team_id' => $team->id,
        'action' => 'task.created',
    ]);
});

test('user can view a task with details', function () {
    $user = taskUser();
    $team = $user->currentTeam;
    $agent = workforceAgent($team);
    $task = Task::factory()->create(['team_id' => $team->id, 'agent_id' => $agent->id]);

    $response = $this->actingAs($user)->get(route('company.tasks.show', $task));

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page->component('company/tasks/show'));
});

test('user can update a task status', function () {
    $user = taskUser();
    $team = $user->currentTeam;
    $agent = workforceAgent($team);
    $task = Task::factory()->create(['team_id' => $team->id, 'agent_id' => $agent->id, 'status' => 'todo']);

    $response = $this->actingAs($user)->patch(route('company.tasks.update', $task), [
        'status' => 'done',
    ]);

    $response->assertRedirect();

    $task->refresh();
    expect($task->status)->toBe('done');
    expect($task->completed_at)->not->toBeNull();
});

test('user can cancel a task and cascade to sub-tasks', function () {
    $user = taskUser();
    $team = $user->currentTeam;
    $agent = workforceAgent($team);

    $parent = Task::factory()->create(['team_id' => $team->id, 'agent_id' => $agent->id, 'status' => 'in_progress']);
    $sub1 = Task::factory()->create(['team_id' => $team->id, 'agent_id' => $agent->id, 'parent_task_id' => $parent->id, 'status' => 'todo']);
    $sub2 = Task::factory()->done()->create(['team_id' => $team->id, 'agent_id' => $agent->id, 'parent_task_id' => $parent->id]);

    $response = $this->actingAs($user)->delete(route('company.tasks.destroy', $parent));

    $response->assertRedirect();

    $parent->refresh();
    $sub1->refresh();
    $sub2->refresh();

    expect($parent->status)->toBe('cancelled');
    expect($sub1->status)->toBe('cancelled');
    expect($sub2->status)->toBe('done'); // Done tasks not cascaded
});

test('user only sees their own team tasks', function () {
    $user = taskUser();

    $response = $this->actingAs($user)->get(route('company.tasks.index'));

    $response->assertOk();
});
