<?php

use App\Enums\TeamRole;
use App\Jobs\UpdateAgentOnServerJob;
use App\Models\Agent;
use App\Models\AgentTelegramConnection;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

test('user can view telegram setup page', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $agent = Agent::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)->get(route('agents.telegram.create', $agent));

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page->component('agents/telegram-setup'));
});

test('user can store telegram connection', function () {
    Bus::fake();
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $agent = Agent::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)->post(route('agents.telegram.store', $agent), [
        'bot_token' => '123456789:ABCdefGHIjklMNOpqrsTUVwxyz_12345678',
    ]);

    $connection = $agent->telegramConnection()->first();
    expect($connection)->not->toBeNull()
        ->and($connection->status->value)->toBe('connected');

    $response->assertRedirect(route('agents.show', $agent));
});

test('pending agent redirects to provisioning after telegram connect', function () {
    Bus::fake();
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $agent = Agent::factory()->pending()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)->post(route('agents.telegram.store', $agent), [
        'bot_token' => '123456789:ABCdefGHIjklMNOpqrsTUVwxyz_12345678',
    ]);

    $response->assertRedirect(route('agents.provisioning', $agent));
});

test('store dispatches update job when agent has server', function () {
    Bus::fake();
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->create(['team_id' => $team->id, 'server_id' => $server->id]);

    $this->actingAs($user)->post(route('agents.telegram.store', $agent), [
        'bot_token' => '123456789:ABCdefGHIjklMNOpqrsTUVwxyz_12345678',
    ]);

    Bus::assertDispatched(UpdateAgentOnServerJob::class);
});

test('user can disconnect telegram', function () {
    Bus::fake();
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $agent = Agent::factory()->create(['team_id' => $team->id]);
    AgentTelegramConnection::factory()->connected()->create(['agent_id' => $agent->id]);

    $response = $this->actingAs($user)->delete(route('agents.telegram.destroy', $agent));

    expect($agent->telegramConnection()->exists())->toBeFalse();
    $response->assertRedirect();
});

test('user cannot access another team agent telegram setup', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $foreignTeam = Team::factory()->subscribed()->create();
    $agent = Agent::factory()->create(['team_id' => $foreignTeam->id]);

    $response = $this->actingAs($user)->get(route('agents.telegram.create', $agent));

    $response->assertNotFound();
});

test('non-admin cannot access telegram setup', function () {
    $team = Team::factory()->subscribed()->create();
    $member = User::factory()->withPersonalTeam()->create();
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $member->forceFill(['current_team_id' => $team->id])->save();
    $agent = Agent::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($member->fresh())->get(route('agents.telegram.create', $agent));

    $response->assertForbidden();
});

test('invalid bot token is rejected', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $agent = Agent::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)->post(route('agents.telegram.store', $agent), [
        'bot_token' => 'not-a-valid-token',
    ]);

    $response->assertSessionHasErrors(['bot_token']);
});
