<?php

use App\Enums\AgentMode;
use App\Models\Agent;
use App\Models\Team;

beforeEach(function () {
    $this->team = Team::factory()->create();
});

it('detects self-referencing cycle', function () {
    $agent = Agent::factory()->create(['team_id' => $this->team->id]);

    expect($agent->validateOrgHierarchy($agent->id))->toBeFalse();
});

it('detects indirect cycle in org hierarchy', function () {
    $ceo = Agent::factory()->create([
        'team_id' => $this->team->id,
        'agent_mode' => AgentMode::Workforce,
    ]);

    $manager = Agent::factory()->create([
        'team_id' => $this->team->id,
        'agent_mode' => AgentMode::Workforce,
        'reports_to' => $ceo->id,
    ]);

    $worker = Agent::factory()->create([
        'team_id' => $this->team->id,
        'agent_mode' => AgentMode::Workforce,
        'reports_to' => $manager->id,
    ]);

    // CEO trying to report to worker would create a cycle
    expect($ceo->validateOrgHierarchy($worker->id))->toBeFalse();
});

it('allows valid org hierarchy', function () {
    $ceo = Agent::factory()->create([
        'team_id' => $this->team->id,
        'agent_mode' => AgentMode::Workforce,
    ]);

    $worker = Agent::factory()->create([
        'team_id' => $this->team->id,
        'agent_mode' => AgentMode::Workforce,
    ]);

    expect($worker->validateOrgHierarchy($ceo->id))->toBeTrue();
});

it('allows null manager (root agent)', function () {
    $agent = Agent::factory()->create(['team_id' => $this->team->id]);

    expect($agent->validateOrgHierarchy(null))->toBeTrue();
});

it('returns chain of command from leaf to root', function () {
    $ceo = Agent::factory()->create([
        'team_id' => $this->team->id,
        'agent_mode' => AgentMode::Workforce,
        'org_title' => 'CEO',
    ]);

    $manager = Agent::factory()->create([
        'team_id' => $this->team->id,
        'agent_mode' => AgentMode::Workforce,
        'reports_to' => $ceo->id,
        'org_title' => 'VP',
    ]);

    $worker = Agent::factory()->create([
        'team_id' => $this->team->id,
        'agent_mode' => AgentMode::Workforce,
        'reports_to' => $manager->id,
        'org_title' => 'Dev',
    ]);

    $chain = $worker->chainOfCommand();

    expect($chain)->toHaveCount(2)
        ->and($chain[0]->id)->toBe($manager->id)
        ->and($chain[1]->id)->toBe($ceo->id);
});

it('returns empty chain for root agent', function () {
    $root = Agent::factory()->create([
        'team_id' => $this->team->id,
        'agent_mode' => AgentMode::Workforce,
    ]);

    expect($root->chainOfCommand())->toBeEmpty();
});

it('lists direct reports', function () {
    $manager = Agent::factory()->create([
        'team_id' => $this->team->id,
        'agent_mode' => AgentMode::Workforce,
    ]);

    $report1 = Agent::factory()->create([
        'team_id' => $this->team->id,
        'reports_to' => $manager->id,
    ]);

    $report2 = Agent::factory()->create([
        'team_id' => $this->team->id,
        'reports_to' => $manager->id,
    ]);

    expect($manager->directReports)->toHaveCount(2);
});
