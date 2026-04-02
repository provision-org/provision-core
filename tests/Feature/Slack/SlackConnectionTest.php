<?php

use App\Enums\TeamRole;
use App\Jobs\UpdateAgentOnServerJob;
use App\Models\Agent;
use App\Models\AgentSlackConnection;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

test('admin can store slack connection with valid tokens', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = Team::factory()->subscribed()->create(['user_id' => $user->id]);
    $team->members()->attach($user, ['role' => TeamRole::Admin->value]);
    $user->forceFill(['current_team_id' => $team->id])->save();
    $agent = Agent::factory()->create(['team_id' => $team->id]);

    $this->actingAs($user->fresh())
        ->post(route('agents.slack.store', $agent), [
            'bot_token' => 'xoxb-test-token-12345',
            'app_token' => 'xapp-test-token-67890',
        ])
        ->assertRedirect(route('agents.slack.create', $agent));

    expect($agent->fresh()->slackConnection)->not->toBeNull()
        ->and($agent->fresh()->slackConnection->status->value)->toBe('disconnected');
});

test('storing slack connection redirects to preferences step', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = Team::factory()->subscribed()->create(['user_id' => $user->id]);
    $team->members()->attach($user, ['role' => TeamRole::Admin->value]);
    $user->forceFill(['current_team_id' => $team->id])->save();
    $agent = Agent::factory()->create(['team_id' => $team->id]);

    $this->actingAs($user->fresh())
        ->post(route('agents.slack.store', $agent), [
            'bot_token' => 'xoxb-test-token-12345',
            'app_token' => 'xapp-test-token-67890',
        ])
        ->assertRedirect(route('agents.slack.create', $agent));

    $connection = $agent->fresh()->slackConnection;
    expect($connection->status->value)->toBe('disconnected')
        ->and($connection->bot_token)->toBe('xoxb-test-token-12345')
        ->and($connection->app_token)->toBe('xapp-test-token-67890');
});

test('preferences step saves settings and sets connected status', function () {
    Bus::fake([UpdateAgentOnServerJob::class]);

    $user = User::factory()->withPersonalTeam()->create();
    $team = Team::factory()->subscribed()->create(['user_id' => $user->id]);
    $team->members()->attach($user, ['role' => TeamRole::Admin->value]);
    $user->forceFill(['current_team_id' => $team->id])->save();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->create(['team_id' => $team->id, 'server_id' => $server->id]);

    AgentSlackConnection::factory()->create([
        'agent_id' => $agent->id,
        'bot_token' => 'xoxb-test-bot-token',
        'app_token' => 'xapp-test-app-token',
        'status' => 'disconnected',
    ]);

    $this->actingAs($user->fresh())
        ->post(route('agents.slack.store-preferences', $agent), [
            'dm_policy' => 'open',
            'group_policy' => 'open',
            'require_mention' => true,
            'reply_to_mode' => 'all',
            'dm_session_scope' => 'per-peer',
        ])
        ->assertRedirect();

    $connection = $agent->fresh()->slackConnection;
    expect($connection->status->value)->toBe('connected')
        ->and($connection->dm_policy)->toBe('open')
        ->and($connection->group_policy)->toBe('open')
        ->and($connection->require_mention)->toBeTrue()
        ->and($connection->reply_to_mode)->toBe('all')
        ->and($connection->dm_session_scope)->toBe('per-peer');

    Bus::assertDispatched(UpdateAgentOnServerJob::class);
});

test('preferences step redirects pending agent to provisioning', function () {
    Bus::fake();

    $user = User::factory()->withPersonalTeam()->create();
    $team = Team::factory()->subscribed()->create(['user_id' => $user->id]);
    $team->members()->attach($user, ['role' => TeamRole::Admin->value]);
    $user->forceFill(['current_team_id' => $team->id])->save();
    $agent = Agent::factory()->pending()->create(['team_id' => $team->id]);

    AgentSlackConnection::factory()->create([
        'agent_id' => $agent->id,
        'bot_token' => 'xoxb-test-bot-token',
        'app_token' => 'xapp-test-app-token',
        'status' => 'disconnected',
    ]);

    $this->actingAs($user->fresh())
        ->post(route('agents.slack.store-preferences', $agent), [
            'dm_policy' => 'disabled',
            'group_policy' => 'open',
            'require_mention' => false,
            'reply_to_mode' => 'first',
            'dm_session_scope' => 'main',
        ])
        ->assertRedirect(route('agents.provisioning', $agent));
});

test('preferences step does not dispatch job when agent has no server', function () {
    Bus::fake([UpdateAgentOnServerJob::class]);

    $user = User::factory()->withPersonalTeam()->create();
    $team = Team::factory()->subscribed()->create(['user_id' => $user->id]);
    $team->members()->attach($user, ['role' => TeamRole::Admin->value]);
    $user->forceFill(['current_team_id' => $team->id])->save();
    $agent = Agent::factory()->create(['team_id' => $team->id, 'server_id' => null]);

    AgentSlackConnection::factory()->create([
        'agent_id' => $agent->id,
        'bot_token' => 'xoxb-test-bot-token',
        'app_token' => 'xapp-test-app-token',
        'status' => 'disconnected',
    ]);

    $this->actingAs($user->fresh())
        ->post(route('agents.slack.store-preferences', $agent), [
            'dm_policy' => 'open',
            'group_policy' => 'disabled',
            'require_mention' => false,
            'reply_to_mode' => 'off',
            'dm_session_scope' => 'main',
        ]);

    Bus::assertNotDispatched(UpdateAgentOnServerJob::class);
});

test('slack token validation rejects invalid bot token', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = Team::factory()->subscribed()->create(['user_id' => $user->id]);
    $team->members()->attach($user, ['role' => TeamRole::Admin->value]);
    $user->forceFill(['current_team_id' => $team->id])->save();
    $agent = Agent::factory()->create(['team_id' => $team->id]);

    $this->actingAs($user->fresh())
        ->post(route('agents.slack.store', $agent), [
            'bot_token' => 'invalid-token',
            'app_token' => 'xapp-test-token',
        ])
        ->assertSessionHasErrors('bot_token');
});

test('slack token validation rejects invalid app token', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = Team::factory()->subscribed()->create(['user_id' => $user->id]);
    $team->members()->attach($user, ['role' => TeamRole::Admin->value]);
    $user->forceFill(['current_team_id' => $team->id])->save();
    $agent = Agent::factory()->create(['team_id' => $team->id]);

    $this->actingAs($user->fresh())
        ->post(route('agents.slack.store', $agent), [
            'bot_token' => 'xoxb-valid-token',
            'app_token' => 'invalid-token',
        ])
        ->assertSessionHasErrors('app_token');
});

test('admin can disconnect slack', function () {
    Bus::fake([UpdateAgentOnServerJob::class]);

    $user = User::factory()->withPersonalTeam()->create();
    $team = Team::factory()->subscribed()->create(['user_id' => $user->id]);
    $team->members()->attach($user, ['role' => TeamRole::Admin->value]);
    $user->forceFill(['current_team_id' => $team->id])->save();
    $agent = Agent::factory()->create(['team_id' => $team->id]);
    AgentSlackConnection::factory()->connected()->create(['agent_id' => $agent->id]);

    $this->actingAs($user->fresh())
        ->delete(route('agents.slack.destroy', $agent))
        ->assertRedirect();

    expect($agent->fresh()->slackConnection)->toBeNull();
});

test('non-admin cannot store slack connection', function () {
    $owner = User::factory()->withPersonalTeam()->create();
    $team = Team::factory()->subscribed()->create(['user_id' => $owner->id]);
    $member = User::factory()->withPersonalTeam()->create();
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $member->forceFill(['current_team_id' => $team->id])->save();
    $agent = Agent::factory()->create(['team_id' => $team->id]);

    $this->actingAs($member->fresh())
        ->post(route('agents.slack.store', $agent), [
            'bot_token' => 'xoxb-test-token',
            'app_token' => 'xapp-test-token',
        ])
        ->assertForbidden();
});

test('agent show page renders without slack connection', function () {
    Bus::fake();

    $user = User::factory()->withPersonalTeam()->create();
    $team = Team::factory()->subscribed()->create(['user_id' => $user->id]);
    $team->members()->attach($user, ['role' => TeamRole::Admin->value]);
    $user->forceFill(['current_team_id' => $team->id])->save();
    $agent = Agent::factory()->create(['team_id' => $team->id]);

    $this->actingAs($user->fresh())
        ->get(route('agents.show', $agent))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('agents/show')
            ->has('agent', fn ($prop) => $prop
                ->where('id', $agent->id)
                ->where('slack_connection', null)
                ->etc()
            )
        );
});

test('non-admin cannot disconnect slack', function () {
    $owner = User::factory()->withPersonalTeam()->create();
    $team = Team::factory()->subscribed()->create(['user_id' => $owner->id]);
    $member = User::factory()->withPersonalTeam()->create();
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $member->forceFill(['current_team_id' => $team->id])->save();
    $agent = Agent::factory()->create(['team_id' => $team->id]);
    AgentSlackConnection::factory()->connected()->create(['agent_id' => $agent->id]);

    $this->actingAs($member->fresh())
        ->delete(route('agents.slack.destroy', $agent))
        ->assertForbidden();
});
