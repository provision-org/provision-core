<?php

use App\Enums\TeamRole;
use App\Models\Agent;
use App\Models\AgentSlackConnection;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('admin can submit xapp token to complete automated setup', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = Team::factory()->subscribed()->create(['user_id' => $user->id]);
    $team->members()->attach($user, ['role' => TeamRole::Admin->value]);
    $user->forceFill(['current_team_id' => $team->id])->save();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->create(['team_id' => $team->id, 'server_id' => $server->id]);

    AgentSlackConnection::factory()->create([
        'agent_id' => $agent->id,
        'slack_app_id' => 'A1234567890',
        'bot_token' => 'xoxb-test-bot-token',
        'client_id' => '1234.5678',
        'client_secret' => 'secret123',
        'signing_secret' => 'signing123',
        'is_automated' => true,
        'status' => 'disconnected',
    ]);

    $this->actingAs($user->fresh())
        ->post(route('agents.slack.store-app-token', $agent), [
            'app_token' => 'xapp-test-app-token-12345',
        ])
        ->assertRedirect(route('agents.slack.create', $agent));

    $connection = $agent->fresh()->slackConnection;
    expect($connection->status->value)->toBe('disconnected')
        ->and($connection->app_token)->toBe('xapp-test-app-token-12345');
});

test('validation rejects invalid xapp token prefix', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = Team::factory()->subscribed()->create(['user_id' => $user->id]);
    $team->members()->attach($user, ['role' => TeamRole::Admin->value]);
    $user->forceFill(['current_team_id' => $team->id])->save();
    $agent = Agent::factory()->create(['team_id' => $team->id]);

    AgentSlackConnection::factory()->create([
        'agent_id' => $agent->id,
        'slack_app_id' => 'A1234567890',
        'bot_token' => 'xoxb-test-bot-token',
        'is_automated' => true,
        'status' => 'disconnected',
    ]);

    $this->actingAs($user->fresh())
        ->post(route('agents.slack.store-app-token', $agent), [
            'app_token' => 'invalid-token',
        ])
        ->assertSessionHasErrors('app_token');
});

test('store-app-token rejects non-automated connection', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = Team::factory()->subscribed()->create(['user_id' => $user->id]);
    $team->members()->attach($user, ['role' => TeamRole::Admin->value]);
    $user->forceFill(['current_team_id' => $team->id])->save();
    $agent = Agent::factory()->create(['team_id' => $team->id]);

    AgentSlackConnection::factory()->connected()->create([
        'agent_id' => $agent->id,
    ]);

    $this->actingAs($user->fresh())
        ->post(route('agents.slack.store-app-token', $agent), [
            'app_token' => 'xapp-test-token',
        ])
        ->assertStatus(422);
});
