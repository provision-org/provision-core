<?php

use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('registration does not create a team', function () {
    $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $user = User::where('email', 'test@example.com')->first();

    expect($user)->not->toBeNull()
        ->and($user->current_team_id)->toBeNull()
        ->and($user->ownedTeams)->toHaveCount(0);
});

test('new user without team is redirected to team creation', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('agents.index'))
        ->assertRedirect(route('teams.create'));
});

test('profiled user without team is redirected to team creation', function () {
    $user = User::factory()->withCompletedProfile()->create();

    $this->actingAs($user)
        ->get(route('agents.index'))
        ->assertRedirect(route('teams.create'));
});
