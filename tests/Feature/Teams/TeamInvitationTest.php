<?php

use App\Enums\TeamRole;
use App\Mail\TeamInvitationMail;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('a team member can invite someone', function () {
    Mail::fake();

    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;

    $response = $this->actingAs($user)->post(route('team-invitations.store', $team), [
        'email' => 'invited@example.com',
        'role' => TeamRole::Member->value,
    ]);

    $response->assertRedirect();

    expect($team->invitations)->toHaveCount(1)
        ->and($team->invitations->first()->email)->toBe('invited@example.com')
        ->and($team->invitations->first()->role)->toBe(TeamRole::Member);

    Mail::assertQueued(TeamInvitationMail::class, function ($mail) {
        return $mail->hasTo('invited@example.com');
    });
});

test('a non-member cannot invite someone', function () {
    Mail::fake();

    $user = User::factory()->withPersonalTeam()->create();
    $foreignTeam = Team::factory()->create();

    $response = $this->actingAs($user)->post(route('team-invitations.store', $foreignTeam), [
        'email' => 'invited@example.com',
        'role' => TeamRole::Member->value,
    ]);

    $response->assertForbidden();
    Mail::assertNotQueued(TeamInvitationMail::class);
});

test('cannot invite an existing team member', function () {
    Mail::fake();

    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;

    $response = $this->actingAs($user)->post(route('team-invitations.store', $team), [
        'email' => $user->email,
        'role' => TeamRole::Member->value,
    ]);

    $response->assertSessionHasErrors('email');
    Mail::assertNotQueued(TeamInvitationMail::class);
});

test('cannot send duplicate invitations', function () {
    Mail::fake();

    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;

    $this->actingAs($user)->post(route('team-invitations.store', $team), [
        'email' => 'invited@example.com',
        'role' => TeamRole::Member->value,
    ]);

    $response = $this->actingAs($user)->post(route('team-invitations.store', $team), [
        'email' => 'invited@example.com',
        'role' => TeamRole::Member->value,
    ]);

    $response->assertSessionHasErrors('email');
    expect($team->invitations)->toHaveCount(1);
});

test('an invited user can accept an invitation', function () {
    $owner = User::factory()->withPersonalTeam()->create();
    $team = $owner->currentTeam;
    $invitedUser = User::factory()->withPersonalTeam()->create();

    $invitation = $team->invitations()->create([
        'email' => $invitedUser->email,
        'role' => TeamRole::Member->value,
    ]);

    $response = $this->actingAs($invitedUser)->get(route('team-invitations.accept', $invitation));

    $response->assertRedirect(route('teams.show', $team));
    expect($team->hasUser($invitedUser))->toBeTrue()
        ->and($invitedUser->fresh()->current_team_id)->toBe($team->id)
        ->and(TeamInvitation::find($invitation->id))->toBeNull();
});

test('a user cannot accept an invitation for a different email', function () {
    $owner = User::factory()->withPersonalTeam()->create();
    $team = $owner->currentTeam;
    $wrongUser = User::factory()->withPersonalTeam()->create();

    $invitation = $team->invitations()->create([
        'email' => 'someone-else@example.com',
        'role' => TeamRole::Member->value,
    ]);

    $response = $this->actingAs($wrongUser)->get(route('team-invitations.accept', $invitation));

    $response->assertForbidden();
    expect($team->hasUser($wrongUser))->toBeFalse();
});

test('an admin can cancel an invitation', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;

    $invitation = $team->invitations()->create([
        'email' => 'invited@example.com',
        'role' => TeamRole::Member->value,
    ]);

    $response = $this->actingAs($user)->delete(route('team-invitations.destroy', $invitation));

    $response->assertRedirect();
    expect(TeamInvitation::find($invitation->id))->toBeNull();
});

test('the invitee can cancel their own invitation', function () {
    $owner = User::factory()->withPersonalTeam()->create();
    $team = $owner->currentTeam;
    $invitedUser = User::factory()->withPersonalTeam()->create();

    $invitation = $team->invitations()->create([
        'email' => $invitedUser->email,
        'role' => TeamRole::Member->value,
    ]);

    $response = $this->actingAs($invitedUser)->delete(route('team-invitations.destroy', $invitation));

    $response->assertRedirect();
    expect(TeamInvitation::find($invitation->id))->toBeNull();
});

test('a non-admin non-invitee cannot cancel an invitation', function () {
    $owner = User::factory()->withPersonalTeam()->create();
    $team = Team::factory()->create(['user_id' => $owner->id]);
    $member = User::factory()->withPersonalTeam()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $invitation = $team->invitations()->create([
        'email' => 'someone@example.com',
        'role' => TeamRole::Member->value,
    ]);

    $response = $this->actingAs($member)->delete(route('team-invitations.destroy', $invitation));

    $response->assertForbidden();
    expect(TeamInvitation::find($invitation->id))->not->toBeNull();
});
