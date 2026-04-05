<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('an admin can change a member role', function () {
    $owner = User::factory()->withPersonalTeam()->create();
    $team = Team::factory()->create(['user_id' => $owner->id]);
    $member = User::factory()->withPersonalTeam()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $response = $this->actingAs($owner)->put(route('team-members.update', [$team, $member]), [
        'role' => TeamRole::Admin->value,
    ]);

    $response->assertRedirect();
    expect($team->hasUserWithRole($member, TeamRole::Admin))->toBeTrue();
});

test('an admin cannot change the owner role', function () {
    $owner = User::factory()->withPersonalTeam()->create();
    $team = Team::factory()->create(['user_id' => $owner->id]);
    $admin = User::factory()->withPersonalTeam()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($admin, ['role' => TeamRole::Admin->value]);

    $response = $this->actingAs($admin)->put(route('team-members.update', [$team, $owner]), [
        'role' => TeamRole::Member->value,
    ]);

    $response->assertForbidden();
});

test('a non-admin cannot change member roles', function () {
    $team = Team::factory()->create();
    $member = User::factory()->withPersonalTeam()->create();
    $otherMember = User::factory()->withPersonalTeam()->create();
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $team->members()->attach($otherMember, ['role' => TeamRole::Member->value]);

    $response = $this->actingAs($member)->put(route('team-members.update', [$team, $otherMember]), [
        'role' => TeamRole::Admin->value,
    ]);

    $response->assertForbidden();
});

test('an admin can remove a member', function () {
    $owner = User::factory()->withPersonalTeam()->create();
    $team = Team::factory()->create(['user_id' => $owner->id]);
    $member = User::factory()->withPersonalTeam()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $member->switchTeam($team);

    $response = $this->actingAs($owner)->delete(route('team-members.destroy', [$team, $member]));

    $response->assertRedirect();
    expect($team->hasUser($member))->toBeFalse()
        ->and($member->fresh()->current_team_id)->toBe($member->personalTeam()->id);
});

test('a member can leave a team', function () {
    $owner = User::factory()->withPersonalTeam()->create();
    $team = Team::factory()->create(['user_id' => $owner->id]);
    $member = User::factory()->withPersonalTeam()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $member->switchTeam($team);

    $response = $this->actingAs($member)->delete(route('team-members.destroy', [$team, $member]));

    $response->assertRedirect();
    expect($team->hasUser($member))->toBeFalse()
        ->and($member->fresh()->current_team_id)->toBe($member->personalTeam()->id);
});

test('the owner cannot be removed from a team', function () {
    $owner = User::factory()->withPersonalTeam()->create();
    $team = Team::factory()->create(['user_id' => $owner->id]);
    $admin = User::factory()->withPersonalTeam()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($admin, ['role' => TeamRole::Admin->value]);

    $response = $this->actingAs($admin)->delete(route('team-members.destroy', [$team, $owner]));

    $response->assertForbidden();
    expect($team->hasUser($owner))->toBeTrue();
});

test('a non-admin cannot remove other members', function () {
    $team = Team::factory()->create();
    $member = User::factory()->withPersonalTeam()->create();
    $otherMember = User::factory()->withPersonalTeam()->create();
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $team->members()->attach($otherMember, ['role' => TeamRole::Member->value]);

    $response = $this->actingAs($member)->delete(route('team-members.destroy', [$team, $otherMember]));

    $response->assertForbidden();
});
