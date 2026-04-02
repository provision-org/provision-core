<?php

use App\Enums\TeamRole;
use App\Jobs\UpdateAgentOnServerJob;
use App\Models\Agent;
use App\Models\AgentDiscordConnection;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Bus;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('user can view discord setup page', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $agent = Agent::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)->get(route('agents.discord.create', $agent));

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page->component('agents/discord-setup'));
});

test('user can store discord connection', function () {
    Bus::fake();
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $agent = Agent::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)->post(route('agents.discord.store', $agent), [
        'token' => 'MTIzNDU2Nzg5.ABCdef.GHIjklMNOpqrsTUVwxyz',
        'guild_id' => '123456789012345678',
        'require_mention' => true,
    ]);

    $connection = $agent->discordConnection()->first();
    expect($connection)->not->toBeNull()
        ->and($connection->status->value)->toBe('connected')
        ->and($connection->guild_id)->toBe('123456789012345678')
        ->and($connection->require_mention)->toBeTrue();

    $response->assertRedirect(route('agents.show', $agent));
});

test('pending agent redirects to provisioning after discord connect', function () {
    Bus::fake();
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $agent = Agent::factory()->pending()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)->post(route('agents.discord.store', $agent), [
        'token' => 'MTIzNDU2Nzg5.ABCdef.GHIjklMNOpqrsTUVwxyz',
    ]);

    $response->assertRedirect(route('agents.provisioning', $agent));
});

test('store dispatches update job when agent has server', function () {
    Bus::fake();
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->create(['team_id' => $team->id, 'server_id' => $server->id]);

    $this->actingAs($user)->post(route('agents.discord.store', $agent), [
        'token' => 'MTIzNDU2Nzg5.ABCdef.GHIjklMNOpqrsTUVwxyz',
    ]);

    Bus::assertDispatched(UpdateAgentOnServerJob::class);
});

test('user can disconnect discord', function () {
    Bus::fake();
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $agent = Agent::factory()->create(['team_id' => $team->id]);
    AgentDiscordConnection::factory()->connected()->create(['agent_id' => $agent->id]);

    $response = $this->actingAs($user)->delete(route('agents.discord.destroy', $agent));

    expect($agent->discordConnection()->exists())->toBeFalse();
    $response->assertRedirect();
});

test('user cannot access another team agent discord setup', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $foreignTeam = Team::factory()->subscribed()->create();
    $agent = Agent::factory()->create(['team_id' => $foreignTeam->id]);

    $response = $this->actingAs($user)->get(route('agents.discord.create', $agent));

    $response->assertNotFound();
});

test('non-admin cannot access discord setup', function () {
    $team = Team::factory()->subscribed()->create();
    $member = User::factory()->withPersonalTeam()->create();
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $member->forceFill(['current_team_id' => $team->id])->save();
    $agent = Agent::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($member->fresh())->get(route('agents.discord.create', $agent));

    $response->assertForbidden();
});

test('guild id is optional', function () {
    Bus::fake();
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $agent = Agent::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)->post(route('agents.discord.store', $agent), [
        'token' => 'MTIzNDU2Nzg5.ABCdef.GHIjklMNOpqrsTUVwxyz',
    ]);

    $connection = $agent->discordConnection()->first();
    expect($connection)->not->toBeNull()
        ->and($connection->guild_id)->toBeNull();

    $response->assertRedirect(route('agents.show', $agent));
});
