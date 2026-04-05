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

beforeEach(function () {
    Bus::fake([UpdateAgentOnServerJob::class]);
});

test('admin can update slack settings', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $agent = Agent::factory()->create(['team_id' => $team->id]);
    AgentSlackConnection::factory()->connected()->create(['agent_id' => $agent->id]);

    $response = $this->actingAs($user)->patch(route('agents.slack.update-settings', $agent), [
        'dm_policy' => 'disabled',
        'group_policy' => 'open',
        'require_mention' => true,
        'reply_to_mode' => 'all',
        'dm_session_scope' => 'per-peer',
    ]);

    $response->assertRedirect();

    $agent->refresh();
    $slack = $agent->slackConnection;
    expect($slack->dm_policy)->toBe('disabled')
        ->and($slack->group_policy)->toBe('open')
        ->and($slack->require_mention)->toBeTrue()
        ->and($slack->reply_to_mode)->toBe('all')
        ->and($slack->dm_session_scope)->toBe('per-peer');
});

test('updating settings dispatches server sync when agent has server', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->create(['team_id' => $team->id, 'server_id' => $server->id]);
    AgentSlackConnection::factory()->connected()->create(['agent_id' => $agent->id]);

    $this->actingAs($user)->patch(route('agents.slack.update-settings', $agent), [
        'dm_policy' => 'open',
        'group_policy' => 'disabled',
        'require_mention' => false,
        'reply_to_mode' => 'first',
        'dm_session_scope' => 'main',
    ]);

    Bus::assertDispatched(UpdateAgentOnServerJob::class, fn ($job) => $job->agent->id === $agent->id);
});

test('updating settings without server does not dispatch job', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $agent = Agent::factory()->create(['team_id' => $team->id, 'server_id' => null]);
    AgentSlackConnection::factory()->connected()->create(['agent_id' => $agent->id]);

    $this->actingAs($user)->patch(route('agents.slack.update-settings', $agent), [
        'dm_policy' => 'open',
        'group_policy' => 'open',
        'require_mention' => false,
        'reply_to_mode' => 'off',
        'dm_session_scope' => 'main',
    ]);

    Bus::assertNotDispatched(UpdateAgentOnServerJob::class);
});

test('invalid dm_policy is rejected', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $agent = Agent::factory()->create(['team_id' => $team->id]);
    AgentSlackConnection::factory()->connected()->create(['agent_id' => $agent->id]);

    $response = $this->actingAs($user)->patch(route('agents.slack.update-settings', $agent), [
        'dm_policy' => 'invalid',
        'group_policy' => 'open',
        'require_mention' => false,
        'reply_to_mode' => 'off',
        'dm_session_scope' => 'main',
    ]);

    $response->assertSessionHasErrors('dm_policy');
});

test('invalid reply_to_mode is rejected', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $agent = Agent::factory()->create(['team_id' => $team->id]);
    AgentSlackConnection::factory()->connected()->create(['agent_id' => $agent->id]);

    $response = $this->actingAs($user)->patch(route('agents.slack.update-settings', $agent), [
        'dm_policy' => 'open',
        'group_policy' => 'open',
        'require_mention' => false,
        'reply_to_mode' => 'always',
        'dm_session_scope' => 'main',
    ]);

    $response->assertSessionHasErrors('reply_to_mode');
});

test('non-admin cannot update slack settings', function () {
    $team = Team::factory()->subscribed()->create();
    $member = User::factory()->withPersonalTeam()->create();
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $member->forceFill(['current_team_id' => $team->id])->save();
    $agent = Agent::factory()->create(['team_id' => $team->id]);
    AgentSlackConnection::factory()->connected()->create(['agent_id' => $agent->id]);

    $response = $this->actingAs($member->fresh())->patch(route('agents.slack.update-settings', $agent), [
        'dm_policy' => 'open',
        'group_policy' => 'open',
        'require_mention' => false,
        'reply_to_mode' => 'off',
        'dm_session_scope' => 'main',
    ]);

    $response->assertForbidden();
});

test('cross-team access is denied', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $foreignTeam = Team::factory()->subscribed()->create();
    $agent = Agent::factory()->create(['team_id' => $foreignTeam->id]);
    AgentSlackConnection::factory()->connected()->create(['agent_id' => $agent->id]);

    $response = $this->actingAs($user)->patch(route('agents.slack.update-settings', $agent), [
        'dm_policy' => 'open',
        'group_policy' => 'open',
        'require_mention' => false,
        'reply_to_mode' => 'off',
        'dm_session_scope' => 'main',
    ]);

    $response->assertNotFound();
});

test('cannot update settings on disconnected slack', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $agent = Agent::factory()->create(['team_id' => $team->id]);
    AgentSlackConnection::factory()->create(['agent_id' => $agent->id]);

    $response = $this->actingAs($user)->patch(route('agents.slack.update-settings', $agent), [
        'dm_policy' => 'open',
        'group_policy' => 'open',
        'require_mention' => false,
        'reply_to_mode' => 'off',
        'dm_session_scope' => 'main',
    ]);

    $response->assertStatus(422);
});

test('default slack settings are applied on new connections', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $agent = Agent::factory()->create(['team_id' => $team->id]);
    AgentSlackConnection::factory()->connected()->create(['agent_id' => $agent->id]);
    $slack = $agent->slackConnection()->first();

    expect($slack->dm_policy)->toBe('open')
        ->and($slack->group_policy)->toBe('open')
        ->and($slack->require_mention)->toBeFalse()
        ->and($slack->reply_to_mode)->toBe('off')
        ->and($slack->dm_session_scope)->toBe('main');
});
