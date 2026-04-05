<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('deleting a user deletes their personal team', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $personalTeamId = $user->currentTeam->id;

    $this->actingAs($user)->delete(route('profile.destroy'), [
        'password' => 'password',
    ]);

    expect(Team::find($personalTeamId))->toBeNull();
});

test('deleting a user transfers ownership of non-personal teams to next admin', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = Team::factory()->create(['user_id' => $user->id]);
    $admin = User::factory()->withPersonalTeam()->create();
    $team->members()->attach($user, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($admin, ['role' => TeamRole::Admin->value]);

    $teamId = $team->id;

    $this->actingAs($user)->delete(route('profile.destroy'), [
        'password' => 'password',
    ]);

    $freshTeam = Team::find($teamId);
    expect($freshTeam)->not->toBeNull()
        ->and($freshTeam->user_id)->toBe($admin->id)
        ->and($freshTeam->hasUser($user))->toBeFalse();
});

test('deleting a user deletes non-personal teams with no other admins', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = Team::factory()->create(['user_id' => $user->id]);
    $member = User::factory()->withPersonalTeam()->create();
    $team->members()->attach($user, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $teamId = $team->id;

    $this->actingAs($user)->delete(route('profile.destroy'), [
        'password' => 'password',
    ]);

    expect(Team::find($teamId))->toBeNull();
});

test('deleting a user detaches them from teams they are just a member of', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $owner = User::factory()->withPersonalTeam()->create();
    $otherTeam = Team::factory()->create(['user_id' => $owner->id]);
    $otherTeam->members()->attach($owner, ['role' => TeamRole::Admin->value]);
    $otherTeam->members()->attach($user, ['role' => TeamRole::Member->value]);

    $this->actingAs($user)->delete(route('profile.destroy'), [
        'password' => 'password',
    ]);

    expect($otherTeam->hasUser($user))->toBeFalse()
        ->and(Team::find($otherTeam->id))->not->toBeNull();
});
