<?php

use App\Enums\TeamRole;
use App\Models\Agent;
use App\Models\SlackConfigurationToken;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('initiateApp creates Slack app and redirects to OAuth', function () {
    Http::fake([
        'slack.com/api/tooling.tokens.rotate' => Http::response([
            'ok' => true,
            'token' => 'xoxe.xoxp-refreshed',
            'refresh_token' => 'xoxe-new-refresh',
            'exp' => now()->addHours(12)->timestamp,
        ]),
        'slack.com/api/apps.manifest.create' => Http::response([
            'ok' => true,
            'app_id' => 'A1234567890',
            'credentials' => [
                'client_id' => '1234.5678',
                'client_secret' => 'secret123',
                'signing_secret' => 'signing123',
                'verification_token' => 'verify123',
            ],
            'oauth_authorize_url' => 'https://slack.com/oauth/v2/authorize?client_id=1234.5678&scope=app_mentions:read',
        ]),
    ]);

    $user = User::factory()->withPersonalTeam()->create();
    $team = Team::factory()->subscribed()->create(['user_id' => $user->id]);
    $team->members()->attach($user, ['role' => TeamRole::Admin->value]);
    $user->forceFill(['current_team_id' => $team->id])->save();
    $agent = Agent::factory()->create(['team_id' => $team->id]);

    SlackConfigurationToken::factory()->expiringSoon()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user->fresh())
        ->post(route('agents.slack.initiate-app', $agent));

    $response->assertRedirect();

    $connection = $agent->fresh()->slackConnection;
    expect($connection)->not->toBeNull()
        ->and($connection->slack_app_id)->toBe('A1234567890')
        ->and($connection->is_automated)->toBeTrue();
});

test('initiateApp fails without config token', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = Team::factory()->subscribed()->create(['user_id' => $user->id]);
    $team->members()->attach($user, ['role' => TeamRole::Admin->value]);
    $user->forceFill(['current_team_id' => $team->id])->save();
    $agent = Agent::factory()->create(['team_id' => $team->id]);

    $this->actingAs($user->fresh())
        ->post(route('agents.slack.initiate-app', $agent))
        ->assertRedirect()
        ->assertSessionHasErrors('config_token');
});

test('initiateApp handles Slack API errors gracefully', function () {
    Http::fake([
        'slack.com/api/apps.manifest.create' => Http::response([
            'ok' => false,
            'error' => 'invalid_manifest',
        ]),
    ]);

    $user = User::factory()->withPersonalTeam()->create();
    $team = Team::factory()->subscribed()->create(['user_id' => $user->id]);
    $team->members()->attach($user, ['role' => TeamRole::Admin->value]);
    $user->forceFill(['current_team_id' => $team->id])->save();
    $agent = Agent::factory()->create(['team_id' => $team->id]);

    SlackConfigurationToken::factory()->create(['team_id' => $team->id]);

    $this->actingAs($user->fresh())
        ->post(route('agents.slack.initiate-app', $agent))
        ->assertRedirect()
        ->assertSessionHasErrors('slack');
});
