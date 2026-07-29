<?php

use App\Jobs\RestartGatewayJob;
use App\Jobs\UpdateAgentOnServerJob;
use App\Models\Agent;
use App\Models\AgentEmailConnection;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

function gmailAgentWithServer(Team $team): Agent
{
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'harness_agent_id' => 'agent-gmail-settings',
    ]);
    AgentEmailConnection::factory()->create([
        'agent_id' => $agent->id,
        'provider' => 'gmail',
        'email_address' => 'old@ruhcare.com',
        'app_password' => 'oldpasswordvalue',
    ]);

    return $agent->fresh(['emailConnection', 'server']);
}

function adminFor(Team $team): User
{
    $user = User::factory()->withCompletedProfile()->create();
    $team->update(['user_id' => $user->id]);
    $team->members()->attach($user, ['role' => 'admin']);
    $user->switchTeam($team);

    return $user;
}

// --- The security fix: secrets must never reach the client ---

test('the app password and webhook secret never serialize into a payload', function () {
    $team = Team::factory()->starterPlan()->create();
    $agent = gmailAgentWithServer($team);

    $serialized = $agent->emailConnection->toArray();

    expect($serialized)
        ->not->toHaveKey('app_password')
        ->not->toHaveKey('mailboxkit_webhook_secret')
        // A safe boolean is exposed instead of the secret itself.
        ->toHaveKey('has_app_password')
        ->and($serialized['has_app_password'])->toBeTrue()
        ->and($agent->emailConnection->toJson())->not->toContain('oldpasswordvalue');
});

// --- Update endpoint ---

test('an admin can update the gmail address and app password, triggering a re-sync', function () {
    Bus::fake();
    $team = Team::factory()->starterPlan()->create();
    $agent = gmailAgentWithServer($team);
    $admin = adminFor($team);

    $this->actingAs($admin)
        ->patch(route('agents.email.gmail', $agent), [
            'gmail_address' => 'new@ruhcare.com',
            'gmail_app_password' => 'wxyz abcd efgh 1234',
        ])
        ->assertRedirect();

    $conn = $agent->emailConnection->fresh();
    expect($conn->email_address)->toBe('new@ruhcare.com')
        // spaces stripped, and it decrypts back to the new value
        ->and($conn->app_password)->toBe('wxyzabcdefgh1234');

    Bus::assertDispatched(UpdateAgentOnServerJob::class);
    Bus::assertDispatched(RestartGatewayJob::class);
});

test('a blank app password keeps the existing one while updating the address', function () {
    Bus::fake();
    $team = Team::factory()->starterPlan()->create();
    $agent = gmailAgentWithServer($team);
    $admin = adminFor($team);

    $this->actingAs($admin)
        ->patch(route('agents.email.gmail', $agent), [
            'gmail_address' => 'moved@ruhcare.com',
            'gmail_app_password' => '',
        ])
        ->assertRedirect();

    $conn = $agent->emailConnection->fresh();
    expect($conn->email_address)->toBe('moved@ruhcare.com')
        ->and($conn->app_password)->toBe('oldpasswordvalue');
});

test('the gmail update endpoint rejects an agent that is not using gmail', function () {
    Bus::fake();
    $team = Team::factory()->starterPlan()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->create(['team_id' => $team->id, 'server_id' => $server->id]);
    AgentEmailConnection::factory()->create([
        'agent_id' => $agent->id,
        'provider' => 'mailboxkit',
        'email_address' => 'bot@provision.ai',
        'mailboxkit_inbox_id' => 'inbox_1',
    ]);
    $admin = adminFor($team);

    $this->actingAs($admin)
        ->patch(route('agents.email.gmail', $agent), [
            'gmail_address' => 'x@y.com',
        ])
        ->assertStatus(422);

    Bus::assertNotDispatched(UpdateAgentOnServerJob::class);
});

test('a non-admin member cannot update gmail settings', function () {
    Bus::fake();
    $team = Team::factory()->starterPlan()->create();
    $agent = gmailAgentWithServer($team);
    adminFor($team);

    $member = User::factory()->withCompletedProfile()->create();
    $team->members()->attach($member, ['role' => 'member']);
    $member->switchTeam($team);

    $this->actingAs($member)
        ->patch(route('agents.email.gmail', $agent), [
            'gmail_address' => 'sneaky@ruhcare.com',
        ])
        ->assertStatus(403);

    expect($agent->emailConnection->fresh()->email_address)->toBe('old@ruhcare.com');
    Bus::assertNotDispatched(UpdateAgentOnServerJob::class);
});
