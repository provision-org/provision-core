<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Support\Provision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

test('a user can view the create team page', function () {
    $user = User::factory()->withPersonalTeam()->create();

    $response = $this->actingAs($user)->get(route('teams.create'));

    $response->assertSuccessful();
});

test('a user can create a new team', function () {
    Bus::fake();

    $user = User::factory()->withPersonalTeam()->create();

    $response = $this->actingAs($user)->post(route('teams.store'), [
        'name' => 'New Team',
    ]);

    $team = Team::where('name', 'New Team')->first();

    expect($team)->not->toBeNull()
        ->and($team->personal_team)->toBeFalse()
        ->and($team->user_id)->toBe($user->id)
        ->and($team->hasUserWithRole($user, TeamRole::Admin))->toBeTrue()
        ->and($user->fresh()->current_team_id)->toBe($team->id);

    $response->assertRedirect(route('agents.index'));
});

test('team creation provisions credit wallet without signup bonus', function () {
    Bus::fake();

    $user = User::factory()->withPersonalTeam()->create();

    $this->actingAs($user)->post(route('teams.store'), [
        'name' => 'Starter Team',
    ]);

    $billingModel = Provision::teamModel();
    $team = $billingModel::where('name', 'Starter Team')->first();

    expect($team->creditWallet)->not->toBeNull()
        ->and($team->creditWallet->balance_cents)->toBe(0);
});

test('a team name is required to create a team', function () {
    $user = User::factory()->withPersonalTeam()->create();

    $response = $this->actingAs($user)->post(route('teams.store'), [
        'name' => '',
    ]);

    $response->assertSessionHasErrors('name');
});

test('a team member can view the team settings page', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;

    $response = $this->actingAs($user)->get(route('teams.show', $team));

    $response->assertSuccessful();
});

test('a non-member cannot view a team settings page', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $foreignTeam = Team::factory()->create();

    $response = $this->actingAs($user)->get(route('teams.show', $foreignTeam));

    $response->assertForbidden();
});

test('an admin can update the team name', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;

    $response = $this->actingAs($user)->patch(route('teams.update', $team), [
        'name' => 'Updated Name',
    ]);

    $response->assertRedirect();
    expect($team->fresh()->name)->toBe('Updated Name');
});

test('an admin can update the team company details', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;

    $response = $this->actingAs($user)->patch(route('teams.update', $team), [
        'name' => $team->name,
        'company_name' => 'Acme Inc.',
        'company_url' => 'https://acme.com',
        'company_description' => 'We make things.',
        'target_market' => 'Small businesses',
    ]);

    $response->assertRedirect();
    $team->refresh();
    expect($team->company_name)->toBe('Acme Inc.')
        ->and($team->company_url)->toBe('https://acme.com')
        ->and($team->company_description)->toBe('We make things.')
        ->and($team->target_market)->toBe('Small businesses');
});

test('a non-admin cannot update the team name', function () {
    $team = Team::factory()->create();
    $member = User::factory()->withPersonalTeam()->create();
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $response = $this->actingAs($member)->patch(route('teams.update', $team), [
        'name' => 'Hacked Name',
    ]);

    $response->assertForbidden();
});

test('the owner can delete a non-personal team', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = Team::factory()->create(['user_id' => $user->id]);
    $team->members()->attach($user, ['role' => TeamRole::Admin->value]);
    $user->switchTeam($team);

    $response = $this->actingAs($user)->delete(route('teams.destroy', $team));

    expect(Team::find($team->id))->toBeNull()
        ->and($user->fresh()->current_team_id)->toBe($user->personalTeam()->id);

    $response->assertRedirect(route('agents.index'));
});

test('a personal team cannot be deleted', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $personalTeam = $user->currentTeam;

    $response = $this->actingAs($user)->delete(route('teams.destroy', $personalTeam));

    $response->assertForbidden();
    expect(Team::find($personalTeam->id))->not->toBeNull();
});

test('a non-owner cannot delete a team', function () {
    $owner = User::factory()->withPersonalTeam()->create();
    $team = Team::factory()->create(['user_id' => $owner->id]);
    $admin = User::factory()->withPersonalTeam()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($admin, ['role' => TeamRole::Admin->value]);

    $response = $this->actingAs($admin)->delete(route('teams.destroy', $team));

    $response->assertForbidden();
});
