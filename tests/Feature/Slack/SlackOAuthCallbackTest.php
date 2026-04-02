<?php

use App\Enums\TeamRole;
use App\Models\Agent;
use App\Models\AgentSlackConnection;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('oauth callback exchanges code for bot token', function () {
    Http::fake([
        'slack.com/api/oauth.v2.access' => Http::response([
            'ok' => true,
            'access_token' => 'xoxb-new-bot-token',
            'team' => ['id' => 'T12345'],
            'bot_user_id' => 'U67890',
        ]),
    ]);

    $user = User::factory()->withPersonalTeam()->create();
    $team = Team::factory()->create(['user_id' => $user->id]);
    $team->members()->attach($user, ['role' => TeamRole::Admin->value]);
    $user->forceFill(['current_team_id' => $team->id])->save();
    $agent = Agent::factory()->create(['team_id' => $team->id]);

    $connection = AgentSlackConnection::factory()->create([
        'agent_id' => $agent->id,
        'slack_app_id' => 'A1234567890',
        'client_id' => '1234.5678',
        'client_secret' => 'secret123',
        'signing_secret' => 'signing123',
        'oauth_state' => 'test-state-123',
        'is_automated' => true,
    ]);

    $this->actingAs($user->fresh())
        ->get(route('slack.oauth.callback', ['code' => 'test-code', 'state' => 'test-state-123']))
        ->assertRedirect(route('agents.slack.create', $agent));

    $connection->refresh();
    expect($connection->bot_token)->toBe('xoxb-new-bot-token')
        ->and($connection->slack_team_id)->toBe('T12345')
        ->and($connection->slack_bot_user_id)->toBe('U67890')
        ->and($connection->oauth_state)->toBeNull();
});

test('oauth callback handles denied authorization', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = Team::factory()->create(['user_id' => $user->id]);
    $team->members()->attach($user, ['role' => TeamRole::Admin->value]);
    $user->forceFill(['current_team_id' => $team->id])->save();

    $this->actingAs($user->fresh())
        ->get(route('slack.oauth.callback', ['error' => 'access_denied']))
        ->assertRedirect(route('agents.index'))
        ->assertSessionHasErrors('slack');
});

test('oauth callback rejects invalid state', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = Team::factory()->create(['user_id' => $user->id]);
    $team->members()->attach($user, ['role' => TeamRole::Admin->value]);
    $user->forceFill(['current_team_id' => $team->id])->save();

    $this->actingAs($user->fresh())
        ->get(route('slack.oauth.callback', ['code' => 'test-code', 'state' => 'invalid-state']))
        ->assertNotFound();
});
