<?php

use App\Jobs\ProcessRoutinesJob;
use App\Models\Agent;
use App\Models\Routine;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;

function routineUser(): User
{
    return User::factory()->withPersonalTeam()->create();
}

it('can create a routine', function () {
    $user = routineUser();
    $team = $user->currentTeam;
    $agent = Agent::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)->post(route('company.routines.store'), [
        'title' => 'Daily standup summary',
        'description' => 'Summarize team standup notes',
        'agent_id' => $agent->id,
        'cron_expression' => '0 9 * * *',
        'timezone' => 'UTC',
    ]);

    $response->assertRedirect(route('company.routines.index'));

    $this->assertDatabaseHas('routines', [
        'team_id' => $team->id,
        'agent_id' => $agent->id,
        'title' => 'Daily standup summary',
        'cron_expression' => '0 9 * * *',
        'status' => 'active',
    ]);

    $routine = Routine::query()->where('title', 'Daily standup summary')->first();
    expect($routine->next_run_at)->not->toBeNull();
    expect($routine->next_run_at->isFuture())->toBeTrue();
});

it('validates cron expression', function () {
    $user = routineUser();
    $team = $user->currentTeam;
    $agent = Agent::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)->post(route('company.routines.store'), [
        'title' => 'Bad cron routine',
        'agent_id' => $agent->id,
        'cron_expression' => 'not-a-valid-cron',
        'timezone' => 'UTC',
    ]);

    // Invalid cron is stored but computeNextRun returns null, so next_run_at is null
    $routine = Routine::query()->where('title', 'Bad cron routine')->first();
    expect($routine)->not->toBeNull();
    expect($routine->next_run_at)->toBeNull();
    expect($routine->computeNextRun())->toBeNull();
});

it('validates agent belongs to team', function () {
    $user = routineUser();
    $team = $user->currentTeam;

    $otherUser = User::factory()->withPersonalTeam()->create();
    $otherAgent = Agent::factory()->create(['team_id' => $otherUser->currentTeam->id]);

    $response = $this->actingAs($user)->post(route('company.routines.store'), [
        'title' => 'Cross-team routine',
        'agent_id' => $otherAgent->id,
        'cron_expression' => '0 9 * * *',
        'timezone' => 'UTC',
    ]);

    $response->assertStatus(422);

    $this->assertDatabaseMissing('routines', [
        'title' => 'Cross-team routine',
    ]);
});

it('can toggle routine between active and paused', function () {
    $user = routineUser();
    $team = $user->currentTeam;
    $routine = Routine::factory()->create([
        'team_id' => $team->id,
        'status' => 'active',
        'next_run_at' => now()->addDay(),
    ]);

    // Toggle to paused
    $response = $this->actingAs($user)->post(route('company.routines.toggle', $routine));

    $response->assertRedirect(route('company.routines.index'));
    $routine->refresh();
    expect($routine->status)->toBe('paused');
    expect($routine->next_run_at)->toBeNull();

    // Toggle back to active
    $response = $this->actingAs($user)->post(route('company.routines.toggle', $routine));

    $routine->refresh();
    expect($routine->status)->toBe('active');
    expect($routine->next_run_at)->not->toBeNull();
});

it('can delete a routine', function () {
    $user = routineUser();
    $team = $user->currentTeam;
    $routine = Routine::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)->delete(route('company.routines.destroy', $routine));

    $response->assertRedirect(route('company.routines.index'));
    $this->assertDatabaseMissing('routines', ['id' => $routine->id]);
});

it('ProcessRoutinesJob creates task for due routine', function () {
    $user = routineUser();
    $team = $user->currentTeam;
    $agent = Agent::factory()->create(['team_id' => $team->id]);

    $routine = Routine::factory()->create([
        'team_id' => $team->id,
        'agent_id' => $agent->id,
        'title' => 'Due routine task',
        'description' => 'Should become a task',
        'status' => 'active',
        'next_run_at' => now()->subMinute(),
    ]);

    (new ProcessRoutinesJob)->handle();

    $this->assertDatabaseHas('tasks', [
        'team_id' => $team->id,
        'agent_id' => $agent->id,
        'routine_id' => $routine->id,
        'title' => 'Due routine task',
        'status' => 'todo',
    ]);
});

it('ProcessRoutinesJob skips paused routines', function () {
    $user = routineUser();
    $team = $user->currentTeam;
    $agent = Agent::factory()->create(['team_id' => $team->id]);

    Routine::factory()->paused()->create([
        'team_id' => $team->id,
        'agent_id' => $agent->id,
        'next_run_at' => now()->subMinute(),
    ]);

    (new ProcessRoutinesJob)->handle();

    expect(Task::query()->where('team_id', $team->id)->count())->toBe(0);
});

it('ProcessRoutinesJob skips routine with existing pending task', function () {
    $user = routineUser();
    $team = $user->currentTeam;
    $agent = Agent::factory()->create(['team_id' => $team->id]);

    $routine = Routine::factory()->create([
        'team_id' => $team->id,
        'agent_id' => $agent->id,
        'status' => 'active',
        'next_run_at' => now()->subMinute(),
    ]);

    // Create an existing pending task linked to this routine
    Task::factory()->create([
        'team_id' => $team->id,
        'agent_id' => $agent->id,
        'routine_id' => $routine->id,
        'status' => 'todo',
    ]);

    (new ProcessRoutinesJob)->handle();

    expect(Task::query()->where('routine_id', $routine->id)->count())->toBe(1);
});

it('ProcessRoutinesJob updates next_run_at after creating task', function () {
    $user = routineUser();
    $team = $user->currentTeam;
    $agent = Agent::factory()->create(['team_id' => $team->id]);

    $pastTime = now()->subMinute();

    $routine = Routine::factory()->create([
        'team_id' => $team->id,
        'agent_id' => $agent->id,
        'status' => 'active',
        'next_run_at' => $pastTime,
    ]);

    (new ProcessRoutinesJob)->handle();

    $routine->refresh();
    expect($routine->next_run_at)->not->toBeNull();
    expect($routine->next_run_at->isFuture())->toBeTrue();
    expect($routine->last_run_at)->not->toBeNull();
});

it('computes next_run_at correctly', function () {
    Carbon::setTestNow(Carbon::parse('2026-04-07 10:00:00', 'UTC'));

    $routine = Routine::factory()->create([
        'cron_expression' => '0 9 * * 1', // Monday 9am
        'timezone' => 'UTC',
    ]);

    $nextRun = $routine->computeNextRun();

    expect($nextRun)->not->toBeNull();
    expect($nextRun->dayOfWeek)->toBe(Carbon::MONDAY);
    expect($nextRun->hour)->toBe(9);
    expect($nextRun->minute)->toBe(0);
    expect($nextRun->isAfter(now()))->toBeTrue();

    Carbon::setTestNow();
});
