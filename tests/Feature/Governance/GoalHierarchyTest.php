<?php

use App\Models\Agent;
use App\Models\Goal;
use App\Models\Task;
use App\Models\Team;

beforeEach(function () {
    $this->team = Team::factory()->create();
});

it('supports parent-child goal hierarchy', function () {
    $parent = Goal::factory()->create(['team_id' => $this->team->id]);
    $child1 = Goal::factory()->create(['team_id' => $this->team->id, 'parent_id' => $parent->id]);
    $child2 = Goal::factory()->create(['team_id' => $this->team->id, 'parent_id' => $parent->id]);

    expect($parent->children)->toHaveCount(2)
        ->and($child1->parent->id)->toBe($parent->id);
});

it('calculates progress from achieved children', function () {
    $parent = Goal::factory()->create(['team_id' => $this->team->id]);
    Goal::factory()->achieved()->create(['team_id' => $this->team->id, 'parent_id' => $parent->id]);
    Goal::factory()->achieved()->create(['team_id' => $this->team->id, 'parent_id' => $parent->id]);
    Goal::factory()->create(['team_id' => $this->team->id, 'parent_id' => $parent->id]);
    Goal::factory()->create(['team_id' => $this->team->id, 'parent_id' => $parent->id]);

    expect($parent->calculateProgress())->toBe(50);
});

it('calculates progress from completed tasks when no children', function () {
    $goal = Goal::factory()->create(['team_id' => $this->team->id]);

    Task::factory()->done()->create(['team_id' => $this->team->id, 'goal_id' => $goal->id]);
    Task::factory()->done()->create(['team_id' => $this->team->id, 'goal_id' => $goal->id]);
    Task::factory()->create(['team_id' => $this->team->id, 'goal_id' => $goal->id]);

    expect($goal->calculateProgress())->toBe(67);
});

it('returns stored progress when no children or tasks', function () {
    $goal = Goal::factory()->create([
        'team_id' => $this->team->id,
        'progress_pct' => 42,
    ]);

    expect($goal->calculateProgress())->toBe(42);
});

it('associates owner agent with goal', function () {
    $agent = Agent::factory()->create(['team_id' => $this->team->id]);
    $goal = Goal::factory()->create([
        'team_id' => $this->team->id,
        'owner_agent_id' => $agent->id,
    ]);

    expect($goal->ownerAgent->id)->toBe($agent->id);
});
