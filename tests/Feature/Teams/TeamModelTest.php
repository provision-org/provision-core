<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('has an owner', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $user->id]);

    expect($team->owner->id)->toBe($user->id);
});

it('has members', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();

    $team->members()->attach($user, ['role' => TeamRole::Member->value]);

    expect($team->members)->toHaveCount(1)
        ->and($team->members->first()->id)->toBe($user->id);
});

it('has invitations', function () {
    $team = Team::factory()->create();
    $invitation = TeamInvitation::factory()->create(['team_id' => $team->id]);

    expect($team->invitations)->toHaveCount(1)
        ->and($team->invitations->first()->id)->toBe($invitation->id);
});

it('checks if a user belongs to the team', function () {
    $team = Team::factory()->create();
    $member = User::factory()->create();
    $nonMember = User::factory()->create();

    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    expect($team->hasUser($member))->toBeTrue()
        ->and($team->hasUser($nonMember))->toBeFalse();
});

it('checks if a user has a specific role', function () {
    $team = Team::factory()->create();
    $admin = User::factory()->create();
    $member = User::factory()->create();

    $team->members()->attach($admin, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    expect($team->hasUserWithRole($admin, TeamRole::Admin))->toBeTrue()
        ->and($team->hasUserWithRole($admin, TeamRole::Member))->toBeFalse()
        ->and($team->hasUserWithRole($member, TeamRole::Member))->toBeTrue()
        ->and($team->hasUserWithRole($member, TeamRole::Admin))->toBeFalse();
});

it('can create a personal team', function () {
    $team = Team::factory()->personalTeam()->create();

    expect($team->personal_team)->toBeTrue();
});

it('creates a user with a personal team via factory', function () {
    $user = User::factory()->withPersonalTeam()->create();

    expect($user->currentTeam)->not->toBeNull()
        ->and($user->currentTeam->personal_team)->toBeTrue()
        ->and($user->currentTeam->owner->id)->toBe($user->id)
        ->and($user->currentTeam->hasUserWithRole($user, TeamRole::Admin))->toBeTrue();
});

it('allows a user to switch teams', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $otherTeam = Team::factory()->create(['user_id' => $user->id]);
    $otherTeam->members()->attach($user, ['role' => TeamRole::Admin->value]);

    $user->switchTeam($otherTeam);

    expect($user->fresh()->current_team_id)->toBe($otherTeam->id);
});

it('checks if user is a team admin', function () {
    $team = Team::factory()->create();
    $admin = User::factory()->create();
    $member = User::factory()->create();

    $team->members()->attach($admin, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    expect($admin->isTeamAdmin($team))->toBeTrue()
        ->and($member->isTeamAdmin($team))->toBeFalse();
});

it('checks if user is a team owner', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $user->id]);
    $otherUser = User::factory()->create();

    expect($user->isTeamOwner($team))->toBeTrue()
        ->and($otherUser->isTeamOwner($team))->toBeFalse();
});

it('returns the personal team for a user', function () {
    $user = User::factory()->withPersonalTeam()->create();

    expect($user->personalTeam())->not->toBeNull()
        ->and($user->personalTeam()->personal_team)->toBeTrue();
});

it('casts invitation role to TeamRole enum', function () {
    $invitation = TeamInvitation::factory()->create(['role' => TeamRole::Admin]);

    expect($invitation->role)->toBe(TeamRole::Admin);
});
