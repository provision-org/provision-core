<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('a user can switch to a team they belong to', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $otherTeam = Team::factory()->create(['user_id' => $user->id]);
    $otherTeam->members()->attach($user, ['role' => TeamRole::Admin->value]);

    $response = $this->actingAs($user)->put(route('current-team.update'), [
        'team_id' => $otherTeam->id,
    ]);

    $response->assertRedirect();
    expect($user->fresh()->current_team_id)->toBe($otherTeam->id);
});

test('a user cannot switch to a team they do not belong to', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $foreignTeam = Team::factory()->create();

    $response = $this->actingAs($user)->put(route('current-team.update'), [
        'team_id' => $foreignTeam->id,
    ]);

    $response->assertForbidden();
    expect($user->fresh()->current_team_id)->not->toBe($foreignTeam->id);
});

test('team_id is required to switch teams', function () {
    $user = User::factory()->withPersonalTeam()->create();

    $response = $this->actingAs($user)->put(route('current-team.update'), []);

    $response->assertSessionHasErrors('team_id');
});

test('team_id must be a valid team', function () {
    $user = User::factory()->withPersonalTeam()->create();

    $response = $this->actingAs($user)->put(route('current-team.update'), [
        'team_id' => 999,
    ]);

    $response->assertSessionHasErrors('team_id');
});

test('guests cannot switch teams', function () {
    $response = $this->put(route('current-team.update'), [
        'team_id' => 1,
    ]);

    $response->assertRedirect(route('login'));
});
