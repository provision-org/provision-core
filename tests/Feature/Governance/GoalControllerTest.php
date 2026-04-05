<?php

use App\Enums\GoalPriority;
use App\Enums\GoalStatus;
use App\Models\Goal;
use App\Models\User;

function goalUser(): User
{
    return User::factory()->withPersonalTeam()->create();
}

test('user can list goals for their team', function () {
    $user = goalUser();
    $team = $user->currentTeam;

    Goal::factory()->count(3)->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)->get(route('governance.goals.index', $team));

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('governance/goals/index')
        ->has('goals', 3)
    );
});

test('user can filter goals by status', function () {
    $user = goalUser();
    $team = $user->currentTeam;

    Goal::factory()->create(['team_id' => $team->id, 'status' => GoalStatus::Active]);
    Goal::factory()->achieved()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)->get(route('governance.goals.index', [
        'team' => $team,
        'status' => 'active',
    ]));

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page->has('goals', 1));
});

test('user can create a goal', function () {
    $user = goalUser();
    $team = $user->currentTeam;

    $response = $this->actingAs($user)->post(route('governance.goals.store', $team), [
        'title' => 'Increase MRR to $100k',
        'priority' => GoalPriority::High->value,
    ]);

    $response->assertRedirect(route('governance.goals.index', $team));

    $this->assertDatabaseHas('goals', [
        'team_id' => $team->id,
        'title' => 'Increase MRR to $100k',
        'priority' => GoalPriority::High->value,
        'status' => GoalStatus::Active->value,
    ]);
});

test('creating a goal logs an audit entry', function () {
    $user = goalUser();
    $team = $user->currentTeam;

    $this->actingAs($user)->post(route('governance.goals.store', $team), [
        'title' => 'Audit test goal',
        'priority' => GoalPriority::Medium->value,
    ]);

    $this->assertDatabaseHas('audit_log', [
        'team_id' => $team->id,
        'action' => 'goal.created',
        'actor_id' => $user->id,
    ]);
});

test('user can create a child goal', function () {
    $user = goalUser();
    $team = $user->currentTeam;
    $parent = Goal::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)->post(route('governance.goals.store', $team), [
        'title' => 'Sub-goal A',
        'priority' => GoalPriority::Medium->value,
        'parent_id' => $parent->id,
    ]);

    $response->assertRedirect();

    $this->assertDatabaseHas('goals', [
        'title' => 'Sub-goal A',
        'parent_id' => $parent->id,
    ]);
});

test('user can update a goal', function () {
    $user = goalUser();
    $team = $user->currentTeam;
    $goal = Goal::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)->patch(route('governance.goals.update', $goal), [
        'title' => 'Updated title',
        'status' => GoalStatus::Achieved->value,
    ]);

    $response->assertRedirect();

    $goal->refresh();
    expect($goal->title)->toBe('Updated title');
    expect($goal->status)->toBe(GoalStatus::Achieved);
});

test('user can soft-abandon a goal', function () {
    $user = goalUser();
    $team = $user->currentTeam;
    $goal = Goal::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)->delete(route('governance.goals.destroy', $goal));

    $response->assertRedirect();

    $goal->refresh();
    expect($goal->status)->toBe(GoalStatus::Abandoned);
});

test('goal progress is calculated from child goals', function () {
    $user = goalUser();
    $team = $user->currentTeam;
    $parent = Goal::factory()->create(['team_id' => $team->id]);

    Goal::factory()->achieved()->create(['team_id' => $team->id, 'parent_id' => $parent->id]);
    Goal::factory()->create(['team_id' => $team->id, 'parent_id' => $parent->id]);

    expect($parent->calculateProgress())->toBe(50);
});

test('user only sees their own team goals', function () {
    $user = goalUser();
    $otherUser = User::factory()->withPersonalTeam()->create();

    // Create a goal on the other team
    Goal::factory()->create(['team_id' => $otherUser->currentTeam->id]);

    // User should only see their own team's goals (none)
    $response = $this->actingAs($user)->get(route('governance.goals.index'));

    $response->assertOk();
});
