<?php

use App\Enums\TeamRole;
use App\Http\Middleware\EnsureHasTeam;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

test('team creation redirects to provisioning page', function () {
    Bus::fake();
    $user = User::factory()->withPersonalTeam()->create();

    $response = $this->actingAs($user)->post(route('teams.store'), [
        'name' => 'New Team',
        'harness_type' => 'openclaw',
    ]);

    $team = Team::where('name', 'New Team')->first();
    $response->assertRedirect(route('teams.provisioning', $team));
    expect($team->harness_type->value)->toBe('openclaw');
});

test('EnsureHasTeam passes through for teams with current_team_id', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = Team::factory()->create(['user_id' => $user->id]);
    $team->members()->attach($user, ['role' => TeamRole::Admin->value]);
    $user->switchTeam($team);

    $request = Request::create('/agents');
    $request->setUserResolver(fn () => $user);

    $response = (new EnsureHasTeam)->handle($request, fn () => response('OK'));

    expect($response->getContent())->toBe('OK');
});

test('EnsureHasTeam redirects to team creation when no current team', function () {
    $user = User::factory()->create(['current_team_id' => null]);

    $request = Request::create('/agents');
    $request->setUserResolver(fn () => $user);

    $response = (new EnsureHasTeam)->handle($request, fn () => response('OK'));

    expect($response->isRedirect(route('teams.create')))->toBeTrue();
});

test('EnsureHasTeam passes through for personal teams', function () {
    $user = User::factory()->withPersonalTeam()->create();

    $request = Request::create('/agents');
    $request->setUserResolver(fn () => $user);

    $response = (new EnsureHasTeam)->handle($request, fn () => response('OK'));

    expect($response->getContent())->toBe('OK');
});
