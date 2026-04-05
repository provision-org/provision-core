<?php

use App\Enums\AgentStatus;
use App\Enums\TeamRole;
use App\Models\Agent;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('pending agent redirects to channel setup', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $agent = Agent::factory()->pending()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)->get(route('agents.setup', $agent));

    $response->assertRedirect(route('agents.channels', $agent));
});

test('deploying agent redirects to provisioning', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $agent = Agent::factory()->deploying()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)->get(route('agents.setup', $agent));

    $response->assertRedirect(route('agents.provisioning', $agent));
});

test('active agent redirects to show', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $agent = Agent::factory()->create(['team_id' => $team->id, 'status' => AgentStatus::Active]);

    $response = $this->actingAs($user)->get(route('agents.setup', $agent));

    $response->assertRedirect(route('agents.show', $agent));
});

test('non-admin cannot access setup page', function () {
    $team = Team::factory()->subscribed()->create();
    $member = User::factory()->withPersonalTeam()->create();
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $member->forceFill(['current_team_id' => $team->id])->save();
    $agent = Agent::factory()->pending()->create(['team_id' => $team->id]);

    $response = $this->actingAs($member->fresh())->get(route('agents.setup', $agent));

    $response->assertForbidden();
});

test('cross-team access to setup page is denied', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $foreignTeam = Team::factory()->create();
    $agent = Agent::factory()->pending()->create(['team_id' => $foreignTeam->id]);

    $response = $this->actingAs($user)->get(route('agents.setup', $agent));

    $response->assertNotFound();
});
