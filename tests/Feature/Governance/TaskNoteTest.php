<?php

use App\Enums\AgentMode;
use App\Models\Agent;
use App\Models\Server;
use App\Models\Task;
use App\Models\TaskNote;
use App\Models\User;

function noteUser(): User
{
    return User::factory()->withPersonalTeam()->create();
}

function noteAgent($team): Agent
{
    return Agent::factory()->create([
        'team_id' => $team->id,
        'agent_mode' => AgentMode::Workforce,
    ]);
}

it('allows a user to add a note to their team task', function () {
    $user = noteUser();
    $team = $user->currentTeam;
    $agent = noteAgent($team);
    $task = Task::factory()->create(['team_id' => $team->id, 'agent_id' => $agent->id]);

    $response = $this->actingAs($user)->post(route('company.tasks.notes.store', $task), [
        'body' => 'This needs more detail.',
    ]);

    $response->assertRedirect();

    $this->assertDatabaseHas('task_notes', [
        'task_id' => $task->id,
        'author_type' => 'user',
        'author_id' => $user->id,
        'body' => 'This needs more detail.',
    ]);
});

it('rejects notes on tasks from another team', function () {
    $user = noteUser();
    $otherUser = noteUser();
    $otherTeam = $otherUser->currentTeam;
    $agent = noteAgent($otherTeam);
    $task = Task::factory()->create(['team_id' => $otherTeam->id, 'agent_id' => $agent->id]);

    $response = $this->actingAs($user)->post(route('company.tasks.notes.store', $task), [
        'body' => 'Should not be allowed.',
    ]);

    $response->assertForbidden();
});

it('transitions done task to todo when user comments', function () {
    $user = noteUser();
    $team = $user->currentTeam;
    $agent = noteAgent($team);
    $task = Task::factory()->done()->create([
        'team_id' => $team->id,
        'agent_id' => $agent->id,
        'checked_out_by_run' => 'run-old',
        'checkout_expires_at' => now()->addHour(),
    ]);

    $this->actingAs($user)->post(route('company.tasks.notes.store', $task), [
        'body' => 'Reopening — needs revision.',
    ]);

    $task->refresh();

    expect($task->status)->toBe('todo');
    expect($task->checked_out_by_run)->toBeNull();
    expect($task->checkout_expires_at)->toBeNull();
});

it('does not change status when commenting on non-done task', function () {
    $user = noteUser();
    $team = $user->currentTeam;
    $agent = noteAgent($team);
    $task = Task::factory()->inProgress()->create([
        'team_id' => $team->id,
        'agent_id' => $agent->id,
    ]);

    $this->actingAs($user)->post(route('company.tasks.notes.store', $task), [
        'body' => 'Just a status update.',
    ]);

    $task->refresh();

    expect($task->status)->toBe('in_progress');
});

it('validates body is required', function () {
    $user = noteUser();
    $team = $user->currentTeam;
    $agent = noteAgent($team);
    $task = Task::factory()->create(['team_id' => $team->id, 'agent_id' => $agent->id]);

    $response = $this->actingAs($user)->post(route('company.tasks.notes.store', $task), [
        'body' => '',
    ]);

    $response->assertSessionHasErrors('body');
});

it('loads notes in task show page', function () {
    $user = noteUser();
    $team = $user->currentTeam;
    $agent = noteAgent($team);
    $task = Task::factory()->create(['team_id' => $team->id, 'agent_id' => $agent->id]);

    TaskNote::factory()->count(3)->create([
        'task_id' => $task->id,
        'author_id' => $user->id,
    ]);

    $response = $this->actingAs($user)->get(route('company.tasks.show', $task));

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('company/tasks/show')
        ->has('task.notes', 3)
    );
});

it('daemon can post agent note via API', function () {
    $user = noteUser();
    $team = $user->currentTeam;

    $server = Server::factory()->running()->create([
        'team_id' => $team->id,
        'daemon_token' => 'test-note-token-456',
    ]);

    $agent = Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'agent_mode' => AgentMode::Workforce,
    ]);

    $task = Task::factory()->create([
        'team_id' => $team->id,
        'agent_id' => $agent->id,
    ]);

    $response = $this->postJson("/api/daemon/test-note-token-456/tasks/{$task->id}/notes", [
        'body' => 'Agent progress update.',
    ]);

    $response->assertOk();

    $this->assertDatabaseHas('task_notes', [
        'task_id' => $task->id,
        'author_type' => 'agent',
        'author_id' => $agent->id,
        'body' => 'Agent progress update.',
    ]);
});
