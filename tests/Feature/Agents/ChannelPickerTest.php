<?php

use App\Enums\TeamRole;
use App\Models\Agent;
use App\Models\AgentSlackConnection;
use App\Models\AgentTelegramConnection;
use App\Models\Team;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('user can view channel picker page', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $agent = Agent::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)->get(route('agents.channels', $agent));

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page->component('agents/channels'));
});

test('pending agent setup redirects to channel picker', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $agent = Agent::factory()->pending()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)->get(route('agents.setup', $agent));

    $response->assertRedirect(route('agents.channels', $agent));
});

test('channel picker shows connected status for configured channels', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $agent = Agent::factory()->create(['team_id' => $team->id]);
    AgentSlackConnection::factory()->connected()->create(['agent_id' => $agent->id]);
    AgentTelegramConnection::factory()->connected()->create(['agent_id' => $agent->id]);

    $response = $this->actingAs($user)->get(route('agents.channels', $agent));

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('agents/channels')
        ->has('agent.slack_connection')
        ->has('agent.telegram_connection')
    );
});

test('user cannot access another team agent channel picker', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $foreignTeam = Team::factory()->create();
    $agent = Agent::factory()->create(['team_id' => $foreignTeam->id]);

    $response = $this->actingAs($user)->get(route('agents.channels', $agent));

    $response->assertNotFound();
});

test('non-admin cannot access channel picker', function () {
    $team = Team::factory()->subscribed()->create();
    $member = User::factory()->withPersonalTeam()->create();
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $member->forceFill(['current_team_id' => $team->id])->save();
    $agent = Agent::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($member->fresh())->get(route('agents.channels', $agent));

    $response->assertForbidden();
});
