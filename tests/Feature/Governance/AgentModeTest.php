<?php

use App\Enums\AgentMode;
use App\Models\Agent;
use App\Models\Team;

it('defaults to channel mode', function () {
    $team = Team::factory()->create();
    $agent = Agent::factory()->create(['team_id' => $team->id]);

    expect($agent->agent_mode)->toBe(AgentMode::Channel)
        ->and($agent->isChannel())->toBeTrue()
        ->and($agent->isWorkforce())->toBeFalse();
});

it('can be created as workforce agent', function () {
    $team = Team::factory()->create();
    $agent = Agent::factory()->create([
        'team_id' => $team->id,
        'agent_mode' => AgentMode::Workforce,
    ]);

    expect($agent->isWorkforce())->toBeTrue()
        ->and($agent->isChannel())->toBeFalse();
});

it('casts agent_mode to AgentMode enum', function () {
    $team = Team::factory()->create();
    $agent = Agent::factory()->create([
        'team_id' => $team->id,
        'agent_mode' => AgentMode::Workforce,
    ]);

    $agent->refresh();

    expect($agent->agent_mode)->toBeInstanceOf(AgentMode::class);
});
