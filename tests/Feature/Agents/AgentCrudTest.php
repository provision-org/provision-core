<?php

use App\Enums\AgentStatus;
use App\Enums\TeamRole;
use App\Jobs\CreateAgentOnServerJob;
use App\Jobs\RemoveAgentFromServerJob;
use App\Jobs\UpdateAgentOnServerJob;
use App\Models\Agent;
use App\Models\AgentEmailConnection;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Provision\MailboxKit\Services\MailboxKitService;

uses(RefreshDatabase::class);

function subscribeCrudTeam(Team $team): void
{
    subscribeTeam($team);
}

test('a team member can view the agents index', function () {
    Bus::fake();
    $user = User::factory()->withPersonalTeam()->create();

    $response = $this->actingAs($user)->get(route('agents.index'));

    $response->assertSuccessful();
});

test('a team member can view the create agent page', function () {
    Bus::fake();
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    Server::factory()->for($team)->running()->create();

    $response = $this->actingAs($user)->get(route('agents.create'));

    $response->assertSuccessful();
});

test('create agent page redirects to provisioning when server is not running', function () {
    Bus::fake();
    $user = User::factory()->withPersonalTeam()->create();

    $response = $this->actingAs($user)->get(route('agents.create'));

    $response->assertRedirect(route('teams.provisioning', $user->currentTeam));
});

test('an admin can create an agent', function () {
    Bus::fake();
    if (class_exists(MailboxKitService::class)) {
        $mock = Mockery::mock(MailboxKitService::class);
        $mock->shouldReceive('createInbox')
            ->once()
            ->withArgs(function (string $name, string $email) {
                return $name === 'Test Agent' && preg_match('/^test_agent_[a-z0-9_]+@provisionagents\.com$/', $email);
            })
            ->andReturn(['data' => ['id' => 1, 'name' => 'Test Agent', 'email' => 'agent_test_agent@provisionagents.com', 'created_at' => now()->toISOString()]]);
        $mock->shouldReceive('createWebhook')->andReturn(['data' => ['id' => 'wh-1', 'secret' => 'wh-secret']]);
        registerMailboxKitModule($mock);
    }

    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    subscribeCrudTeam($team);

    $response = $this->actingAs($user)->post(route('agents.store'), [
        'name' => 'Test Agent',
        'role' => 'custom',
        'model_primary' => 'claude-opus-4-6',
    ]);

    $agent = Agent::where('name', 'Test Agent')->first();

    expect($agent)->not->toBeNull()
        ->and($agent->team_id)->toBe($team->id)
        ->and($agent->status)->toBe(AgentStatus::Pending);

    if (class_exists(MailboxKitService::class)) {
        $emailConnection = $agent->emailConnection;
        expect($emailConnection)->not->toBeNull()
            ->and($emailConnection->email_address)->toMatch('/^test_agent_[a-z0-9_]+@provisionagents\.com$/')
            ->and((int) $emailConnection->mailboxkit_inbox_id)->toBe(1)
            ->and($emailConnection->status)->toBe('connected');
    }

    $response->assertRedirect(route('agents.setup', $agent));
    Bus::assertNotDispatched(CreateAgentOnServerJob::class);
});

test('an admin can create an agent with a job description', function () {
    Bus::fake();
    if (class_exists(MailboxKitService::class)) {
        $mock = Mockery::mock(MailboxKitService::class);
        $mock->shouldReceive('createInbox')->once()->andReturn([
            'data' => ['id' => 2, 'name' => 'Marketing Bot', 'email' => 'marketing@provisionagents.com', 'created_at' => now()->toISOString()],
        ]);
        $mock->shouldReceive('createWebhook')->andReturn(['data' => ['id' => 'wh-2', 'secret' => 'wh-secret']]);
        registerMailboxKitModule($mock);
    }

    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    subscribeCrudTeam($team);

    $jobDescription = 'Pull daily reports from Mixpanel and Google Analytics. Post a morning brief to Slack.';

    $this->actingAs($user)->post(route('agents.store'), [
        'name' => 'Marketing Bot',
        'role' => 'custom',
        'model_primary' => 'claude-opus-4-6',
        'job_description' => $jobDescription,
    ]);

    $agent = Agent::where('name', 'Marketing Bot')->first();

    expect($agent)->not->toBeNull()
        ->and($agent->job_description)->toBe($jobDescription);
});

test('store validates required fields', function () {
    Bus::fake();
    $user = User::factory()->withPersonalTeam()->create();
    subscribeCrudTeam($user->currentTeam);

    $response = $this->actingAs($user)->post(route('agents.store'), []);

    $response->assertSessionHasErrors(['name', 'role']);
});

test('a non-admin cannot create an agent', function () {
    $team = Team::factory()->subscribed()->create();
    $member = User::factory()->withPersonalTeam()->create();
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $member->forceFill(['current_team_id' => $team->id])->save();

    $response = $this->actingAs($member->fresh())->post(route('agents.store'), [
        'name' => 'Test Agent',
        'role' => 'custom',
    ]);

    $response->assertForbidden();
});

test('a team member can view an agent show page', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $agent = Agent::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)->get(route('agents.show', $agent));

    $response->assertSuccessful();
});

test('show page includes is_syncing in agent props', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $agent = Agent::factory()->create(['team_id' => $team->id, 'is_syncing' => true]);

    $response = $this->actingAs($user)->get(route('agents.show', $agent));

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('agents/show')
        ->where('agent.is_syncing', true)
    );
});

test('show page includes per-agent browser url when server has vnc password', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->create(['team_id' => $team->id, 'server_id' => $server->id, 'name' => 'Atlas']);

    $response = $this->actingAs($user)->get(route('agents.show', $agent));

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('agents/show')
        ->where('browserUrl', fn ($url) => str_contains($url, '/agents/')
            && str_contains($url, '/browser')
            && str_contains($url, 'signature='))
    );
});

test('show page has null browser url when server has no vnc password', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $server = Server::factory()->running()->create(['team_id' => $team->id, 'vnc_password' => null]);
    $agent = Agent::factory()->create(['team_id' => $team->id, 'server_id' => $server->id]);

    $response = $this->actingAs($user)->get(route('agents.show', $agent));

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('agents/show')
        ->where('browserUrl', null)
    );
});

test('a user cannot view an agent from another team', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $foreignTeam = Team::factory()->create();
    $agent = Agent::factory()->create(['team_id' => $foreignTeam->id]);

    $response = $this->actingAs($user)->get(route('agents.show', $agent));

    $response->assertNotFound();
});

test('a team member can view the configure page', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $agent = Agent::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)->get(route('agents.configure', $agent));

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('agents/configure')
        ->has('agent')
        ->has('availableModels')
    );
});

test('a user cannot view configure page for another teams agent', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $foreignTeam = Team::factory()->create();
    $agent = Agent::factory()->create(['team_id' => $foreignTeam->id]);

    $response = $this->actingAs($user)->get(route('agents.configure', $agent));

    $response->assertNotFound();
});

test('an admin can view the edit agent page', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $agent = Agent::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)->get(route('agents.edit', $agent));

    $response->assertSuccessful();
});

test('a non-admin cannot view the edit agent page', function () {
    $team = Team::factory()->subscribed()->create();
    $member = User::factory()->withPersonalTeam()->create();
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $member->forceFill(['current_team_id' => $team->id])->save();
    $agent = Agent::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($member->fresh())->get(route('agents.edit', $agent));

    $response->assertForbidden();
});

test('an admin can update an agent', function () {
    Bus::fake();
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $agent = Agent::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)->patch(route('agents.update', $agent), [
        'name' => 'Updated Agent Name',
    ]);

    $response->assertRedirect();
    expect($agent->fresh()->name)->toBe('Updated Agent Name');
});

test('a non-admin cannot update an agent', function () {
    $team = Team::factory()->subscribed()->create();
    $member = User::factory()->withPersonalTeam()->create();
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $member->forceFill(['current_team_id' => $team->id])->save();
    $agent = Agent::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($member->fresh())->patch(route('agents.update', $agent), [
        'name' => 'Hacked Name',
    ]);

    $response->assertForbidden();
});

test('a user cannot update an agent from another team', function () {
    Bus::fake();
    $user = User::factory()->withPersonalTeam()->create();
    $foreignTeam = Team::factory()->create();
    $agent = Agent::factory()->create(['team_id' => $foreignTeam->id]);

    $response = $this->actingAs($user)->patch(route('agents.update', $agent), [
        'name' => 'Hacked Name',
    ]);

    $response->assertNotFound();
});

test('an admin can delete an agent', function () {
    Bus::fake();
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $agent = Agent::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)->delete(route('agents.destroy', $agent));

    expect(Agent::find($agent->id))->toBeNull();
    $response->assertRedirect(route('agents.index'));
});

test('a non-admin cannot delete an agent', function () {
    $team = Team::factory()->subscribed()->create();
    $member = User::factory()->withPersonalTeam()->create();
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $member->forceFill(['current_team_id' => $team->id])->save();
    $agent = Agent::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($member->fresh())->delete(route('agents.destroy', $agent));

    $response->assertForbidden();
    expect(Agent::find($agent->id))->not->toBeNull();
});

test('update dispatches UpdateAgentOnServerJob when agent has server', function () {
    Bus::fake();
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->create(['team_id' => $team->id, 'server_id' => $server->id]);

    $this->actingAs($user)->patch(route('agents.update', $agent), [
        'name' => 'Updated Name',
    ]);

    Bus::assertDispatched(UpdateAgentOnServerJob::class, function ($job) use ($agent) {
        return $job->agent->id === $agent->id;
    });
});

test('update sets is_syncing when agent has server', function () {
    Bus::fake();
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->create(['team_id' => $team->id, 'server_id' => $server->id]);

    $this->actingAs($user)->patch(route('agents.update', $agent), [
        'name' => 'Updated Name',
    ]);

    expect($agent->fresh()->is_syncing)->toBeTrue();
});

test('update does not set is_syncing when agent has no server', function () {
    Bus::fake();
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $agent = Agent::factory()->create(['team_id' => $team->id, 'server_id' => null]);

    $this->actingAs($user)->patch(route('agents.update', $agent), [
        'name' => 'Updated Name',
    ]);

    expect($agent->fresh()->is_syncing)->toBeFalse();
});

test('update does not dispatch job when agent has no server', function () {
    Bus::fake();
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $agent = Agent::factory()->create(['team_id' => $team->id, 'server_id' => null]);

    $this->actingAs($user)->patch(route('agents.update', $agent), [
        'name' => 'Updated Name',
    ]);

    Bus::assertNotDispatched(UpdateAgentOnServerJob::class);
});

test('destroy dispatches RemoveAgentFromServerJob when agent has server', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = Team::factory()->subscribed()->create(['user_id' => $user->id]);
    $team->members()->attach($user, ['role' => 'admin']);
    $user->switchTeam($team);
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'harness_agent_id' => 'agent-test-destroy',
    ]);

    Bus::fake();

    $this->actingAs($user)->delete(route('agents.destroy', $agent));

    Bus::assertDispatched(RemoveAgentFromServerJob::class);
});

test('destroy does not dispatch job when agent has no server', function () {
    Bus::fake();
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $agent = Agent::factory()->create(['team_id' => $team->id, 'server_id' => null]);

    $this->actingAs($user)->delete(route('agents.destroy', $agent));

    Bus::assertNotDispatched(RemoveAgentFromServerJob::class);
});

test('openclawModelConfig returns string when no fallbacks', function () {
    $agent = Agent::factory()->create(['model_primary' => 'claude-opus-4-6', 'model_fallbacks' => null]);

    expect($agent->openclawModelConfig())->toBe('openrouter/anthropic/claude-opus-4-6');
});

test('openclawModelConfig returns object with fallbacks', function () {
    $agent = Agent::factory()->create([
        'model_primary' => 'claude-opus-4-6',
        'model_fallbacks' => ['gpt-5-nano', 'z-ai/glm-4.7'],
    ]);

    $config = $agent->openclawModelConfig();

    expect($config)->toBeArray()
        ->and($config['primary'])->toBe('openrouter/anthropic/claude-opus-4-6')
        ->and($config['fallbacks'])->toBe(['openrouter/openai/gpt-5-nano', 'openrouter/z-ai/glm-4.7']);
});

test('openclawModelConfig returns string when fallbacks is empty array', function () {
    $agent = Agent::factory()->create(['model_primary' => 'claude-opus-4-6', 'model_fallbacks' => []]);

    expect($agent->openclawModelConfig())->toBe('openrouter/anthropic/claude-opus-4-6');
});

test('agent creation provisions mailboxkit email with correct slug format', function () {
    if (! class_exists(MailboxKitService::class)) {
        $this->markTestSkipped('MailboxKit module not installed');
    }

    Bus::fake();
    $mock = Mockery::mock(MailboxKitService::class);
    $mock->shouldReceive('createInbox')
        ->once()
        ->withArgs(function (string $name, string $email) {
            return $name === 'Support Bot' && preg_match('/^support_bot_[a-z0-9_]+@provisionagents\.com$/', $email);
        })
        ->andReturn(['data' => ['id' => 42, 'name' => 'Support Bot', 'email' => 'agent_support_bot@provisionagents.com', 'created_at' => now()->toISOString()]]);
    $mock->shouldReceive('createWebhook')->andReturn(['data' => ['id' => 'wh-42', 'secret' => 'wh-secret']]);
    registerMailboxKitModule($mock);

    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    subscribeCrudTeam($team);

    $this->actingAs($user)->post(route('agents.store'), [
        'name' => 'Support Bot',
        'role' => 'customer_support',
        'model_primary' => 'claude-opus-4-6',
    ]);

    $agent = Agent::where('name', 'Support Bot')->first();
    $emailConnection = $agent->emailConnection;

    expect($emailConnection)->not->toBeNull()
        ->and($emailConnection->email_address)->toMatch('/^support_bot_[a-z0-9_]+@provisionagents\.com$/')
        ->and((int) $emailConnection->mailboxkit_inbox_id)->toBe(42)
        ->and($emailConnection->status)->toBe('connected');
});

test('agent creation continues when mailboxkit provisioning fails', function () {
    if (! class_exists(MailboxKitService::class)) {
        $this->markTestSkipped('MailboxKit module not installed');
    }

    Bus::fake();
    $mock = Mockery::mock(MailboxKitService::class);
    $mock->shouldReceive('createInbox')
        ->once()
        ->andThrow(new RuntimeException('API down'));
    registerMailboxKitModule($mock);

    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    subscribeCrudTeam($team);

    $response = $this->actingAs($user)->post(route('agents.store'), [
        'name' => 'Fail Bot',
        'role' => 'custom',
        'model_primary' => 'claude-opus-4-6',
    ]);

    $agent = Agent::where('name', 'Fail Bot')->first();
    expect($agent)->not->toBeNull()
        ->and($agent->emailConnection)->toBeNull();

    $response->assertRedirect(route('agents.setup', $agent));
});

test('agent deletion calls mailboxkit to delete inbox', function () {
    if (! class_exists(MailboxKitService::class)) {
        $this->markTestSkipped('MailboxKit module not installed');
    }

    Bus::fake();
    $mock = Mockery::mock(MailboxKitService::class);
    $mock->shouldReceive('createInbox')->andReturn(['data' => ['id' => 1]]);
    $mock->shouldReceive('createWebhook')->andReturn(['data' => ['id' => 'wh-1', 'secret' => 'wh-secret']]);
    $mock->shouldReceive('deleteInbox')
        ->once()
        ->with(100);
    registerMailboxKitModule($mock);

    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $agent = Agent::factory()->create(['team_id' => $team->id]);
    AgentEmailConnection::factory()->create([
        'agent_id' => $agent->id,
        'mailboxkit_inbox_id' => 100,
        'status' => 'connected',
    ]);

    $this->actingAs($user)->delete(route('agents.destroy', $agent));

    expect(Agent::find($agent->id))->toBeNull();
});

test('agent deletion continues when mailboxkit inbox deletion fails', function () {
    if (! class_exists(MailboxKitService::class)) {
        $this->markTestSkipped('MailboxKit module not installed');
    }

    Bus::fake();
    $mock = Mockery::mock(MailboxKitService::class);
    $mock->shouldReceive('createInbox')->andReturn(['data' => ['id' => 1]]);
    $mock->shouldReceive('createWebhook')->andReturn(['data' => ['id' => 'wh-1', 'secret' => 'wh-secret']]);
    $mock->shouldReceive('deleteInbox')
        ->once()
        ->andThrow(new RuntimeException('API down'));
    registerMailboxKitModule($mock);

    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $agent = Agent::factory()->create(['team_id' => $team->id]);
    AgentEmailConnection::factory()->create([
        'agent_id' => $agent->id,
        'mailboxkit_inbox_id' => 200,
        'status' => 'connected',
    ]);

    $response = $this->actingAs($user)->delete(route('agents.destroy', $agent));

    expect(Agent::find($agent->id))->toBeNull();
    $response->assertRedirect(route('agents.index'));
});
