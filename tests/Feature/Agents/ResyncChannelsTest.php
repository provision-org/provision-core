<?php

use App\Enums\AgentStatus;
use App\Jobs\VerifyAgentChannelsJob;
use App\Models\Agent;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Bus;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('an admin can resync channels for an active agent', function () {
    Bus::fake();

    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'status' => AgentStatus::Active,
        'harness_agent_id' => 'test-agent-id',
    ]);

    $response = $this->actingAs($user)->post(route('agents.resync-channels', $agent));

    $response->assertRedirect();
    $response->assertSessionHas('status');

    Bus::assertDispatched(VerifyAgentChannelsJob::class, function ($job) use ($agent) {
        return $job->agent->id === $agent->id;
    });
});

test('resync channels is rejected for a pending agent', function () {
    Bus::fake();

    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $agent = Agent::factory()->create([
        'team_id' => $team->id,
        'status' => AgentStatus::Pending,
    ]);

    $response = $this->actingAs($user)->post(route('agents.resync-channels', $agent));

    $response->assertStatus(422);

    Bus::assertNotDispatched(VerifyAgentChannelsJob::class);
});

test('resync channels is rejected for non-team agents', function () {
    Bus::fake();

    $user = User::factory()->withPersonalTeam()->create();
    $otherTeam = Team::factory()->create();
    $server = Server::factory()->running()->create(['team_id' => $otherTeam->id]);
    $agent = Agent::factory()->create([
        'team_id' => $otherTeam->id,
        'server_id' => $server->id,
        'status' => AgentStatus::Active,
    ]);

    $response = $this->actingAs($user)->post(route('agents.resync-channels', $agent));

    $response->assertNotFound();

    Bus::assertNotDispatched(VerifyAgentChannelsJob::class);
});
