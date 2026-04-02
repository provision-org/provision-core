<?php

use App\Http\Middleware\EnsureHasTeam;
use App\Models\User;
use Illuminate\Http\Request;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('middleware redirects to team creation when current_team_id is null', function () {
    $user = User::factory()->create();

    $request = Request::create('/dashboard');
    $request->setUserResolver(fn () => $user);

    $response = (new EnsureHasTeam)->handle($request, fn () => response('OK'));

    expect($response->isRedirect(route('teams.create')))->toBeTrue();
});

test('middleware passes through when user has current_team_id set', function () {
    $user = User::factory()->withPersonalTeam()->create();

    $request = Request::create('/dashboard');
    $request->setUserResolver(fn () => $user);

    $response = (new EnsureHasTeam)->handle($request, fn () => response('OK'));

    expect($response->getContent())->toBe('OK');
});

test('middleware passes through for guests', function () {
    $request = Request::create('/dashboard');
    $request->setUserResolver(fn () => null);

    $response = (new EnsureHasTeam)->handle($request, fn () => response('OK'));

    expect($response->getContent())->toBe('OK');
});
