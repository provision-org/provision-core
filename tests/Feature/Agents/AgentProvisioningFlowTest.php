<?php

use App\Enums\AgentRole;
use App\Enums\AgentStatus;
use App\Enums\ServerStatus;
use App\Jobs\CreateAgentOnServerJob;
use App\Jobs\ProvisionDigitalOceanServerJob;
use App\Jobs\ProvisionHetznerServerJob;
use App\Models\Agent;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

function subscribeProvisioningTeam(Team $team): void
{
    subscribeTeam($team);
}

test('creating an agent sets status to pending and does not dispatch CreateAgentOnServerJob', function () {
    Bus::fake();
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    subscribeProvisioningTeam($team);
    Server::factory()->running()->create(['team_id' => $team->id]);

    $this->actingAs($user)->post(route('agents.store', $team), [
        'name' => 'Second Agent',
        'role' => AgentRole::Custom->value,
    ]);

    $agent = Agent::where('name', 'Second Agent')->first();

    expect($agent->status)->toBe(AgentStatus::Pending);
    Bus::assertNotDispatched(CreateAgentOnServerJob::class);
    Bus::assertNotDispatched(ProvisionHetznerServerJob::class);
    Bus::assertNotDispatched(ProvisionDigitalOceanServerJob::class);
});

test('visiting provisioning page for pending agent with running server dispatches CreateAgentOnServerJob', function () {
    Bus::fake();
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->pending()->create(['team_id' => $team->id]);

    $this->actingAs($user)->get(route('agents.provisioning', $agent));

    Bus::assertDispatched(CreateAgentOnServerJob::class);
    expect($agent->fresh()->status)->toBe(AgentStatus::Deploying);
});

test('creating an agent on a team with a non-running server does not dispatch any server job from provisioning', function () {
    Bus::fake();
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    Server::factory()->create(['team_id' => $team->id, 'status' => ServerStatus::Provisioning]);
    $agent = Agent::factory()->pending()->create(['team_id' => $team->id]);

    $this->actingAs($user)->get(route('agents.provisioning', $agent));

    Bus::assertNotDispatched(CreateAgentOnServerJob::class);
    Bus::assertNotDispatched(ProvisionHetznerServerJob::class);
    Bus::assertNotDispatched(ProvisionDigitalOceanServerJob::class);
});
