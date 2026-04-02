<?php

use App\Enums\TeamRole;
use App\Models\SlackConfigurationToken;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createAdminUser(): array
{
    $user = User::factory()->withPersonalTeam()->create();
    $team = Team::factory()->create(['user_id' => $user->id]);
    $team->members()->attach($user, ['role' => TeamRole::Admin->value]);
    $user->forceFill(['current_team_id' => $team->id])->save();

    return [$user->fresh(), $team];
}

test('admin can store slack configuration token', function () {
    [$user, $team] = createAdminUser();

    $this->actingAs($user)
        ->post(route('teams.slack-config.store', $team), [
            'access_token' => 'xoxe.xoxp-test-token-12345',
            'refresh_token' => 'xoxe-test-refresh-token',
        ])
        ->assertRedirect();

    expect($team->fresh()->slackConfigurationToken)->not->toBeNull();
});

test('admin can replace existing slack configuration token', function () {
    [$user, $team] = createAdminUser();

    SlackConfigurationToken::factory()->create(['team_id' => $team->id]);

    $this->actingAs($user)
        ->post(route('teams.slack-config.store', $team), [
            'access_token' => 'xoxe.xoxp-new-token',
            'refresh_token' => 'xoxe-new-refresh',
        ])
        ->assertRedirect();

    expect(SlackConfigurationToken::where('team_id', $team->id)->count())->toBe(1);
});

test('admin can delete slack configuration token', function () {
    [$user, $team] = createAdminUser();

    SlackConfigurationToken::factory()->create(['team_id' => $team->id]);

    $this->actingAs($user)
        ->delete(route('teams.slack-config.destroy', $team))
        ->assertRedirect();

    expect($team->fresh()->slackConfigurationToken)->toBeNull();
});

test('validation rejects invalid access token prefix', function () {
    [$user, $team] = createAdminUser();

    $this->actingAs($user)
        ->post(route('teams.slack-config.store', $team), [
            'access_token' => 'invalid-token',
            'refresh_token' => 'xoxe-test-refresh',
        ])
        ->assertSessionHasErrors('access_token');
});

test('validation rejects invalid refresh token prefix', function () {
    [$user, $team] = createAdminUser();

    $this->actingAs($user)
        ->post(route('teams.slack-config.store', $team), [
            'access_token' => 'xoxe.xoxp-valid-token',
            'refresh_token' => 'invalid-refresh',
        ])
        ->assertSessionHasErrors('refresh_token');
});

test('non-admin gets 403 when storing config token', function () {
    $owner = User::factory()->withPersonalTeam()->create();
    $team = Team::factory()->create(['user_id' => $owner->id]);
    $member = User::factory()->withPersonalTeam()->create();
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $member->forceFill(['current_team_id' => $team->id])->save();

    $this->actingAs($member->fresh())
        ->post(route('teams.slack-config.store', $team), [
            'access_token' => 'xoxe.xoxp-test-token',
            'refresh_token' => 'xoxe-test-refresh',
        ])
        ->assertForbidden();
});

test('non-admin gets 403 when deleting config token', function () {
    $owner = User::factory()->withPersonalTeam()->create();
    $team = Team::factory()->create(['user_id' => $owner->id]);
    $member = User::factory()->withPersonalTeam()->create();
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $member->forceFill(['current_team_id' => $team->id])->save();

    SlackConfigurationToken::factory()->create(['team_id' => $team->id]);

    $this->actingAs($member->fresh())
        ->delete(route('teams.slack-config.destroy', $team))
        ->assertForbidden();
});
