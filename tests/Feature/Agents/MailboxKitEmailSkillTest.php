<?php

use App\Contracts\CommandExecutor;
use App\Contracts\HarnessDriver;
use App\Jobs\RestartGatewayJob;
use App\Jobs\UpdateAgentOnServerJob;
use App\Models\Agent;
use App\Models\AgentEmailConnection;
use App\Models\Server;
use App\Models\Team;
use App\Services\AgentInstallScriptService;
use App\Services\HarnessManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Provision\MailboxKit\MailboxKitModule;

uses(RefreshDatabase::class);

// --- Install script tests ---

test('install script includes mailboxkit skill when agent has email connection', function () {
    if (! class_exists(MailboxKitModule::class)) {
        $this->markTestSkipped('MailboxKit module not installed');
    }

    config(['mailboxkit.api_key' => 'mbk-test-key']);

    $team = Team::factory()->starterPlan()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->deploying()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'harness_agent_id' => 'agent-email-test',
        'system_prompt' => 'You are helpful.',
    ]);
    AgentEmailConnection::factory()->create([
        'agent_id' => $agent->id,
        'mailboxkit_inbox_id' => 123,
        'email_address' => 'agent@provisionagents.com',
    ]);

    $script = app(AgentInstallScriptService::class)->generateScript($agent);

    expect($script)
        ->toContain('/skills/mailboxkit')
        ->toContain('SKILL.md')
        ->not->toContain('mailboxkit_tool.js')
        ->not->toContain('/skills/mailboxkit/skill.json')
        ->not->toContain('/skills/mailboxkit/package.json')
        ->toContain('"mailboxkit"')
        // Values are baked directly into per-agent SKILL.md (no env var references)
        ->toContain('mbk-test-key')
        ->toContain('123')
        ->toContain('agent@provisionagents.com')
        ->toContain('TOOLS.md')
        ->toContain('Your email address')
        ->toContain('threads')
        ->toContain('attachments');
});

test('install script omits mailboxkit skill when agent has no email connection', function () {
    $team = Team::factory()->starterPlan()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->deploying()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'harness_agent_id' => 'agent-no-email',
    ]);

    $script = app(AgentInstallScriptService::class)->generateScript($agent);

    expect($script)
        ->not->toContain('mkdir -p /root/.openclaw/skills/mailboxkit')
        ->not->toContain('"mailboxkit"')
        ->not->toContain('MAILBOXKIT_INBOX_ID')
        ->not->toContain('MAILBOXKIT_EMAIL')
        ->not->toContain('Your email address');
});

test('install script skill patch does not include apiKey', function () {
    if (! class_exists(MailboxKitModule::class)) {
        $this->markTestSkipped('MailboxKit module not installed');
    }

    config(['mailboxkit.api_key' => 'mbk-test-key']);

    $team = Team::factory()->starterPlan()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->deploying()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'harness_agent_id' => 'agent-no-apikey',
        'system_prompt' => 'You are helpful.',
    ]);
    AgentEmailConnection::factory()->create([
        'agent_id' => $agent->id,
        'mailboxkit_inbox_id' => 456,
        'email_address' => 'bot@provisionagents.com',
    ]);

    $script = app(AgentInstallScriptService::class)->generateScript($agent);

    // Skill patch should have enabled: true but NOT apiKey
    expect($script)->toContain('{ enabled: true }');
    expect($script)->not->toContain('apiKey');
});

// --- UpdateAgentOnServerJob tests ---

test('update agent job adds mailboxkit skill config and workspace env when email connection exists', function () {
    Bus::fake([RestartGatewayJob::class]);
    config(['mailboxkit.api_key' => 'mbk-test-key']);

    $team = Team::factory()->starterPlan()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'harness_agent_id' => 'agent-email-update',
        'system_prompt' => null,
        'identity' => null,
    ]);
    AgentEmailConnection::factory()->create([
        'agent_id' => $agent->id,
        'mailboxkit_inbox_id' => 789,
        'email_address' => 'bot@provisionagents.com',
    ]);

    $executor = Mockery::mock(CommandExecutor::class);

    $driver = Mockery::mock(HarnessDriver::class);
    $driver->shouldReceive('updateAgent')
        ->once()
        ->andReturnUsing(function (Agent $a) {
            $a->update([
                'config_snapshot' => [
                    'skills' => ['entries' => ['mailboxkit' => ['enabled' => true]]],
                ],
                'is_syncing' => false,
                'last_synced_at' => now(),
            ]);
        });

    $harnessManager = Mockery::mock(HarnessManager::class);
    $harnessManager->shouldReceive('resolveExecutor')->once()->andReturn($executor);
    $harnessManager->shouldReceive('forAgent')->once()->andReturn($driver);

    (new UpdateAgentOnServerJob($agent))->handle($harnessManager);

    $agent->refresh();

    // Verify config_snapshot contains skill config
    expect($agent->config_snapshot)->toBeArray()
        ->and($agent->config_snapshot['skills']['entries']['mailboxkit'])->toBe(['enabled' => true])
        ->and($agent->config_snapshot['env'] ?? [])->not->toHaveKey('MAILBOXKIT_API_KEY')
        ->and($agent->config_snapshot['env'] ?? [])->not->toHaveKey('MAILBOXKIT_INBOX_ID')
        ->and($agent->config_snapshot['env'] ?? [])->not->toHaveKey('MAILBOXKIT_EMAIL')
        ->and($agent->is_syncing)->toBeFalse()
        ->and($agent->last_synced_at)->not->toBeNull();
});

test('update agent job skips mailboxkit config when no email connection', function () {
    Bus::fake([RestartGatewayJob::class]);

    $team = Team::factory()->starterPlan()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'harness_agent_id' => 'agent-no-email-update',
        'system_prompt' => null,
        'identity' => null,
    ]);

    $executor = Mockery::mock(CommandExecutor::class);

    $driver = Mockery::mock(HarnessDriver::class);
    $driver->shouldReceive('updateAgent')
        ->once()
        ->andReturnUsing(function (Agent $a) {
            $a->update([
                'config_snapshot' => [],
                'is_syncing' => false,
                'last_synced_at' => now(),
            ]);
        });

    $harnessManager = Mockery::mock(HarnessManager::class);
    $harnessManager->shouldReceive('resolveExecutor')->once()->andReturn($executor);
    $harnessManager->shouldReceive('forAgent')->once()->andReturn($driver);

    (new UpdateAgentOnServerJob($agent))->handle($harnessManager);

    $agent->refresh();

    // Verify no mailboxkit skill in snapshot
    expect($agent->config_snapshot)->toBeArray()
        ->and($agent->config_snapshot['skills']['entries'] ?? [])->not->toHaveKey('mailboxkit')
        ->and($agent->is_syncing)->toBeFalse()
        ->and($agent->last_synced_at)->not->toBeNull();
});
