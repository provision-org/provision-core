<?php

use App\Enums\AgentMode;
use App\Models\Agent;
use App\Models\Goal;
use App\Models\Task;
use App\Models\Team;

beforeEach(function () {
    $this->team = Team::factory()->create();
});

it('tracks delegation depth in sub-tasks', function () {
    $manager = Agent::factory()->create([
        'team_id' => $this->team->id,
        'agent_mode' => AgentMode::Workforce,
        'delegation_enabled' => true,
    ]);

    $report = Agent::factory()->create([
        'team_id' => $this->team->id,
        'agent_mode' => AgentMode::Workforce,
        'reports_to' => $manager->id,
    ]);

    $parentTask = Task::factory()->create([
        'team_id' => $this->team->id,
        'agent_id' => $manager->id,
        'request_depth' => 0,
    ]);

    $subTask = Task::factory()->create([
        'team_id' => $this->team->id,
        'agent_id' => $report->id,
        'parent_task_id' => $parentTask->id,
        'delegated_by' => $manager->id,
        'request_depth' => 1,
    ]);

    expect($subTask->parentTask->id)->toBe($parentTask->id)
        ->and($subTask->delegatedByAgent->id)->toBe($manager->id)
        ->and($subTask->request_depth)->toBe(1);
});

it('links sub-tasks back to parent task', function () {
    $parentTask = Task::factory()->create(['team_id' => $this->team->id]);

    $sub1 = Task::factory()->create([
        'team_id' => $this->team->id,
        'parent_task_id' => $parentTask->id,
    ]);

    $sub2 = Task::factory()->create([
        'team_id' => $this->team->id,
        'parent_task_id' => $parentTask->id,
    ]);

    expect($parentTask->subTasks)->toHaveCount(2);
});

it('inherits goal from parent task', function () {
    $goal = Goal::factory()->create(['team_id' => $this->team->id]);

    $parentTask = Task::factory()->create([
        'team_id' => $this->team->id,
        'goal_id' => $goal->id,
    ]);

    $subTask = Task::factory()->create([
        'team_id' => $this->team->id,
        'parent_task_id' => $parentTask->id,
        'goal_id' => $goal->id,
    ]);

    expect($subTask->goal->id)->toBe($goal->id)
        ->and($parentTask->goal->id)->toBe($goal->id);
});
