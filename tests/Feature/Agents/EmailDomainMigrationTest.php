<?php

use App\Jobs\RefreshAgentEmailJob;
use App\Models\Agent;
use App\Models\AgentEmailConnection;
use App\Models\Server;
use App\Models\Team;
use App\Models\TeamEmailDomain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Provision\MailboxKit\Services\EmailProvisioningService;
use Provision\MailboxKit\Services\MailboxKitService;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (! class_exists(MailboxKitService::class)) {
        $this->markTestSkipped('MailboxKit module not installed');
    }
    config(['mailboxkit.email_domain' => 'provisionagents.com']);
});

function verifiedDomain(Team $team, string $name = 'acme.com'): TeamEmailDomain
{
    return TeamEmailDomain::create([
        'team_id' => $team->id,
        'mailboxkit_domain_id' => 'mbk-dom-1',
        'name' => $name,
        'is_verified' => true,
    ]);
}

// --- availableDomains / resolveDomain ---

test('availableDomains returns only the default when no custom domain', function () {
    $team = Team::factory()->create();

    $domains = app(EmailProvisioningService::class)->availableDomains($team);

    expect($domains)->toHaveCount(1)
        ->and($domains[0])->toMatchArray(['name' => 'provisionagents.com', 'is_default' => true, 'is_verified' => true]);
});

test('availableDomains includes a verified custom domain', function () {
    $team = Team::factory()->create();
    verifiedDomain($team);

    $domains = app(EmailProvisioningService::class)->availableDomains($team);

    expect($domains)->toHaveCount(2)
        ->and($domains[1])->toMatchArray(['name' => 'acme.com', 'is_default' => false, 'is_verified' => true]);
});

test('availableDomains flags an unverified custom domain', function () {
    $team = Team::factory()->create();
    TeamEmailDomain::create([
        'team_id' => $team->id, 'mailboxkit_domain_id' => 'd', 'name' => 'acme.com', 'is_verified' => false,
    ]);

    $domains = app(EmailProvisioningService::class)->availableDomains($team);

    expect($domains[1]['is_verified'])->toBeFalse();
});

test('resolveDomain returns the requested domain only when verified', function () {
    $team = Team::factory()->create();
    verifiedDomain($team);
    $service = app(EmailProvisioningService::class);

    expect($service->resolveDomain($team, 'acme.com'))->toBe('acme.com')
        ->and($service->resolveDomain($team, 'unknown.com'))->toBe('acme.com') // fallback = active (verified custom)
        ->and($service->resolveDomain($team, null))->toBe('acme.com');
});

test('resolveDomain falls back to default when requested domain is unverified', function () {
    $team = Team::factory()->create();
    TeamEmailDomain::create([
        'team_id' => $team->id, 'mailboxkit_domain_id' => 'd', 'name' => 'acme.com', 'is_verified' => false,
    ]);
    $service = app(EmailProvisioningService::class);

    expect($service->resolveDomain($team, 'acme.com'))->toBe('provisionagents.com');
});

// --- provisionEmail domain override ---

test('provisionEmail uses a requested verified domain', function () {
    config(['mailboxkit.api_key' => 'k']);
    $team = Team::factory()->create();
    verifiedDomain($team);
    $agent = Agent::factory()->create(['team_id' => $team->id]);

    $mock = Mockery::mock(MailboxKitService::class);
    $mock->shouldReceive('createInbox')->once()
        ->with(Mockery::any(), Mockery::pattern('/@acme\.com$/'))
        ->andReturn(['data' => ['id' => 1]]);
    $mock->shouldReceive('createWebhook')->andReturn(['data' => ['id' => 'w', 'secret' => 's']]);
    app()->instance(MailboxKitService::class, $mock);

    $email = app(EmailProvisioningService::class)->provisionEmail($agent, $team, 'sales', 'acme.com');

    expect($email)->toBe('sales@acme.com');
});

test('provisionEmail ignores an unverified requested domain and uses the default', function () {
    config(['mailboxkit.api_key' => 'k']);
    $team = Team::factory()->create();
    TeamEmailDomain::create([
        'team_id' => $team->id, 'mailboxkit_domain_id' => 'd', 'name' => 'acme.com', 'is_verified' => false,
    ]);
    $agent = Agent::factory()->create(['team_id' => $team->id]);

    $mock = Mockery::mock(MailboxKitService::class);
    $mock->shouldReceive('createInbox')->once()
        ->with(Mockery::any(), Mockery::pattern('/@provisionagents\.com$/'))
        ->andReturn(['data' => ['id' => 1]]);
    $mock->shouldReceive('createWebhook')->andReturn(['data' => ['id' => 'w', 'secret' => 's']]);
    app()->instance(MailboxKitService::class, $mock);

    $email = app(EmailProvisioningService::class)->provisionEmail($agent, $team, 'sales', 'acme.com');

    expect($email)->toBe('sales@provisionagents.com');
});

// --- changeEmailDomain ---

test('changeEmailDomain creates a new inbox, swaps the connection, and deletes the old inbox', function () {
    $team = Team::factory()->create();
    verifiedDomain($team);
    $agent = Agent::factory()->create([
        'team_id' => $team->id,
        'identity' => "# Bot\n- **DOB:** Jan 1, 2026\n- **Email:** kate_w@provisionagents.com\n",
    ]);
    AgentEmailConnection::factory()->create([
        'agent_id' => $agent->id,
        'email_address' => 'kate_w@provisionagents.com',
        'mailboxkit_inbox_id' => 'old-inbox',
    ]);

    $mock = Mockery::mock(MailboxKitService::class);
    $mock->shouldReceive('createInbox')->once()
        ->with(Mockery::any(), 'kate_w@acme.com')
        ->andReturn(['data' => ['id' => 'new-inbox']]);
    $mock->shouldReceive('deleteInbox')->once()->with('old-inbox');
    app()->instance(MailboxKitService::class, $mock);

    $newEmail = app(EmailProvisioningService::class)->changeEmailDomain($agent, $team, 'acme.com');

    $conn = $agent->fresh()->emailConnection;
    expect($newEmail)->toBe('kate_w@acme.com')
        ->and($conn->email_address)->toBe('kate_w@acme.com')
        ->and($conn->mailboxkit_inbox_id)->toBe('new-inbox')
        ->and($agent->fresh()->identity)->toContain('- **Email:** kate_w@acme.com')
        ->and($agent->fresh()->identity)->not->toContain('kate_w@provisionagents.com');
});

test('changeEmailDomain is a no-op when already on the target domain', function () {
    $team = Team::factory()->create();
    $agent = Agent::factory()->create(['team_id' => $team->id]);
    AgentEmailConnection::factory()->create([
        'agent_id' => $agent->id,
        'email_address' => 'kate_w@provisionagents.com',
        'mailboxkit_inbox_id' => 'old-inbox',
    ]);

    $mock = Mockery::mock(MailboxKitService::class);
    $mock->shouldReceive('createInbox')->never();
    app()->instance(MailboxKitService::class, $mock);

    $result = app(EmailProvisioningService::class)->changeEmailDomain($agent, $team, 'provisionagents.com');

    expect($result)->toBe('kate_w@provisionagents.com');
});

test('setEmailInIdentity replaces an existing email line instead of duplicating it', function () {
    $service = app(EmailProvisioningService::class);
    $identity = "# Bot\n- **DOB:** Jan 1, 2026\n- **Email:** old@x.com\n\n## Next";

    $result = $service->setEmailInIdentity($identity, 'new@y.com');

    expect(substr_count($result, '- **Email:**'))->toBe(1)
        ->and($result)->toContain('- **Email:** new@y.com')
        ->and($result)->not->toContain('old@x.com');
});

// --- Controller endpoint ---

test('an admin can move an agent to a verified custom domain and the agent re-syncs', function () {
    Queue::fake();
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    verifiedDomain($team);

    $agent = Agent::factory()->create(['team_id' => $team->id, 'server_id' => $server->id]);
    AgentEmailConnection::factory()->create([
        'agent_id' => $agent->id, 'email_address' => 'kate_w@provisionagents.com', 'mailboxkit_inbox_id' => 'old',
    ]);

    $mock = Mockery::mock(MailboxKitService::class);
    $mock->shouldReceive('createInbox')->once()->andReturn(['data' => ['id' => 'new']]);
    $mock->shouldReceive('deleteInbox')->once();
    registerMailboxKitModule($mock);

    $this->actingAs($user)
        ->post(route('agents.email-domain', $agent), ['domain' => 'acme.com'])
        ->assertRedirect();

    expect($agent->fresh()->emailConnection->email_address)->toBe('kate_w@acme.com');
    Queue::assertPushed(RefreshAgentEmailJob::class);
});

test('changing to a non-allowed domain is rejected', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    verifiedDomain($team);
    $agent = Agent::factory()->create(['team_id' => $team->id, 'server_id' => $server->id]);
    AgentEmailConnection::factory()->create(['agent_id' => $agent->id, 'email_address' => 'k@provisionagents.com']);

    $mock = Mockery::mock(MailboxKitService::class);
    registerMailboxKitModule($mock);

    $this->actingAs($user)
        ->post(route('agents.email-domain', $agent), ['domain' => 'evil.com'])
        ->assertSessionHasErrors('domain');
});
