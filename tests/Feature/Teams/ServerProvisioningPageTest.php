<?php

use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

test('team creation redirects to provisioning', function () {
    Bus::fake();
    $user = User::factory()->withPersonalTeam()->create();

    $response = $this->actingAs($user)->post(route('teams.store'), [
        'name' => 'New Team',
        'harness_type' => 'hermes',
    ]);

    $team = Team::where('name', 'New Team')->first();
    $response->assertRedirect(route('teams.provisioning', $team));
});

test('provisioning page renders with server status props', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = Team::factory()->create(['user_id' => $user->id]);
    $team->members()->attach($user, ['role' => TeamRole::Admin->value]);
    $server = Server::factory()->provisioning()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)->get(route('teams.provisioning', $team));

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('settings/teams/provisioning')
        ->has('team')
        ->where('team.id', $team->id)
        ->where('team.name', $team->name)
        ->has('server')
        ->where('server.id', $server->id)
        ->where('server.status', ServerStatus::Provisioning->value)
    );
});

test('provisioning page redirects to agents when server is running', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = Team::factory()->create(['user_id' => $user->id]);
    $team->members()->attach($user, ['role' => TeamRole::Admin->value]);
    Server::factory()->running()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)->get(route('teams.provisioning', $team));

    $response->assertRedirect(route('agents.index'));
});

test('non-members cannot access provisioning page', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = Team::factory()->create();
    Server::factory()->provisioning()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)->get(route('teams.provisioning', $team));

    $response->assertForbidden();
});

test('provisioning page renders error state', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = Team::factory()->create(['user_id' => $user->id]);
    $team->members()->attach($user, ['role' => TeamRole::Admin->value]);
    $server = Server::factory()->error()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)->get(route('teams.provisioning', $team));

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('settings/teams/provisioning')
        ->where('server.status', ServerStatus::Error->value)
    );
});

test('provisioning page includes server events', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = Team::factory()->create(['user_id' => $user->id]);
    $team->members()->attach($user, ['role' => TeamRole::Admin->value]);
    $server = Server::factory()->provisioning()->create(['team_id' => $team->id]);

    $server->events()->create(['event' => 'provisioning_started', 'payload' => []]);
    $server->events()->create(['event' => 'cloud_init_progress', 'payload' => ['step' => 'installing_packages']]);
    $server->events()->create(['event' => 'cloud_init_progress', 'payload' => ['step' => 'installing_openclaw']]);

    $response = $this->actingAs($user)->get(route('teams.provisioning', $team));

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('settings/teams/provisioning')
        ->has('server.events', 3)
        ->where('server.events.0.event', 'provisioning_started')
        ->where('server.events.1.event', 'cloud_init_progress')
        ->where('server.events.1.step', 'installing_packages')
        ->where('server.events.2.step', 'installing_openclaw')
    );
});

test('provisioning page handles missing server', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = Team::factory()->create(['user_id' => $user->id]);
    $team->members()->attach($user, ['role' => TeamRole::Admin->value]);

    $response = $this->actingAs($user)->get(route('teams.provisioning', $team));

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('settings/teams/provisioning')
        ->where('server', null)
    );
});
