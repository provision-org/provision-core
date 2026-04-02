<?php

use App\Enums\TeamRole;
use App\Jobs\UpdateAgentOnServerJob;
use App\Models\Agent;
use App\Models\AgentSlackConnection;
use App\Models\SlackConfigurationToken;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('disconnecting automated connection deletes Slack app via API', function () {
    Bus::fake([UpdateAgentOnServerJob::class]);
    Http::fake([
        'slack.com/api/apps.manifest.delete' => Http::response(['ok' => true]),
    ]);

    $user = User::factory()->withPersonalTeam()->create();
    $team = Team::factory()->subscribed()->create(['user_id' => $user->id]);
    $team->members()->attach($user, ['role' => TeamRole::Admin->value]);
    $user->forceFill(['current_team_id' => $team->id])->save();
    $agent = Agent::factory()->create(['team_id' => $team->id]);

    SlackConfigurationToken::factory()->create(['team_id' => $team->id]);
    AgentSlackConnection::factory()->automated()->create(['agent_id' => $agent->id]);

    $this->actingAs($user->fresh())
        ->delete(route('agents.slack.destroy', $agent))
        ->assertRedirect();

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'apps.manifest.delete');
    });

    expect($agent->fresh()->slackConnection)->toBeNull();
});

test('disconnecting manual connection skips API call', function () {
    Bus::fake([UpdateAgentOnServerJob::class]);
    Http::fake();

    $user = User::factory()->withPersonalTeam()->create();
    $team = Team::factory()->subscribed()->create(['user_id' => $user->id]);
    $team->members()->attach($user, ['role' => TeamRole::Admin->value]);
    $user->forceFill(['current_team_id' => $team->id])->save();
    $agent = Agent::factory()->create(['team_id' => $team->id]);

    AgentSlackConnection::factory()->connected()->create(['agent_id' => $agent->id]);

    $this->actingAs($user->fresh())
        ->delete(route('agents.slack.destroy', $agent))
        ->assertRedirect();

    Http::assertNothingSent();

    expect($agent->fresh()->slackConnection)->toBeNull();
});

test('deleting agent with automated connection deletes Slack app', function () {
    Bus::fake();
    Http::fake([
        'slack.com/api/apps.manifest.delete' => Http::response(['ok' => true]),
    ]);

    $user = User::factory()->withPersonalTeam()->create();
    $team = Team::factory()->subscribed()->create(['user_id' => $user->id]);
    $team->members()->attach($user, ['role' => TeamRole::Admin->value]);
    $user->forceFill(['current_team_id' => $team->id])->save();
    $agent = Agent::factory()->create(['team_id' => $team->id]);

    SlackConfigurationToken::factory()->create(['team_id' => $team->id]);
    AgentSlackConnection::factory()->automated()->create(['agent_id' => $agent->id]);

    $this->actingAs($user->fresh())
        ->delete(route('agents.destroy', $agent))
        ->assertRedirect();

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'apps.manifest.delete');
    });
});
