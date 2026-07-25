<?php

use App\Models\Agent;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('a team member can open a fresh desktop session for an ascii box', function () {
    config()->set('cloud.ascii.api_token', 'test-token');
    config()->set('cloud.ascii.base_url', 'https://ascii.test/api');

    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $server = Server::factory()->running()->ascii()->create([
        'team_id' => $team->id,
        'provider_server_id' => 'bx_23456789',
    ]);
    $agent = Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
    ]);
    $desktopUrl = 'https://desktop.ascii.test/stream.html?token=secret-session';

    Http::fake([
        'ascii.test/api/boxes/bx_23456789/desktop*' => Http::response([
            'ok' => true,
            'type' => 'desktop.url',
            'desktopUrl' => $desktopUrl,
        ]),
    ]);

    $response = $this->actingAs($user)->get(route('agents.desktop', $agent));

    $response->assertRedirect($desktopUrl);
    expect($response->headers->get('Cache-Control'))->toContain('no-store');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://ascii.test/api/boxes/bx_23456789/desktop?theme=light'
        && $request->hasHeader('Authorization', 'Bearer test-token'));
});

test('the agent page exposes only the local desktop route for an ascii box', function () {
    config()->set('inertia.ssr.enabled', false);
    Http::preventStrayRequests();

    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $server = Server::factory()->running()->ascii()->create([
        'team_id' => $team->id,
        'provider_server_id' => 'bx_23456789',
    ]);
    $agent = Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
    ]);

    $response = $this->actingAs($user)->get(route('agents.show', $agent));

    $response->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('agents/show')
            ->where('browserUrl', null)
            ->where('desktopUrl', route('agents.desktop', $agent))
        );

    Http::assertNothingSent();
});

test('a user cannot open another teams ascii desktop', function () {
    Http::preventStrayRequests();

    $user = User::factory()->withPersonalTeam()->create();
    $foreignTeam = Team::factory()->create();
    $foreignServer = Server::factory()->running()->ascii()->create([
        'team_id' => $foreignTeam->id,
        'provider_server_id' => 'bx_23456789',
    ]);
    $agent = Agent::factory()->create([
        'team_id' => $foreignTeam->id,
        'server_id' => $foreignServer->id,
    ]);

    $this->actingAs($user)
        ->get(route('agents.desktop', $agent))
        ->assertNotFound();

    Http::assertNothingSent();
});

test('the desktop route rejects a non-ascii server without calling ascii', function () {
    Http::preventStrayRequests();

    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
    ]);

    $this->actingAs($user)
        ->get(route('agents.desktop', $agent))
        ->assertNotFound();

    Http::assertNothingSent();
});
