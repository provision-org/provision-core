<?php

use App\Models\Agent;
use App\Models\AgentTemplate;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Provision\MailboxKit\Services\EmailProvisioningService;
use Provision\MailboxKit\Services\MailboxKitService;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (! class_exists(MailboxKitService::class)) {
        $this->markTestSkipped('MailboxKit module not installed');
    }
});

function subscribeEmailTeam(Team $team): void
{
    subscribeTeam($team);
    Server::factory()->running()->create(['team_id' => $team->id]);
}

test('generateEmailSlug follows correct format', function () {
    $service = app(EmailProvisioningService::class);

    $slug = $service->generateEmailSlug('Support Bot', 'Acme Corp');

    expect($slug)->toBe('support_bot_acme_corp@'.config('mailboxkit.email_domain', 'provisionagents.com'));
});

test('provisionEmail creates connection with webhook and stores env var', function () {
    config(['mailboxkit.api_key' => 'mbk-test-key']);

    $mock = Mockery::mock(MailboxKitService::class);
    $mock->shouldReceive('createInbox')
        ->once()
        ->andReturn(['data' => ['id' => 42]]);
    $mock->shouldReceive('createWebhook')
        ->once()
        ->andReturn(['data' => ['id' => 'wh-10', 'secret' => 'wh-secret-abc']]);
    $this->app->instance(MailboxKitService::class, $mock);

    $team = Team::factory()->starterPlan()->create();
    $agent = Agent::factory()->create(['team_id' => $team->id]);

    $service = app(EmailProvisioningService::class);
    $email = $service->provisionEmail($agent, $team);

    $conn = $agent->fresh()->emailConnection;

    expect($email)->not->toBeNull()
        ->and($email)->toMatch('/^[a-z0-9_]+@provisionagents\.com$/')
        ->and($conn)->not->toBeNull()
        ->and((int) $conn->mailboxkit_inbox_id)->toBe(42)
        ->and($conn->mailboxkit_webhook_id)->toBe('wh-10')
        ->and($conn->mailboxkit_webhook_secret)->toBe('wh-secret-abc')
        ->and($conn->status)->toBe('connected');

    expect($team->envVars()->where('key', 'MAILBOXKIT_API_KEY')->exists())->toBeTrue();
});

test('provisionEmail continues when webhook registration fails', function () {
    config(['mailboxkit.api_key' => 'mbk-test-key']);

    $mock = Mockery::mock(MailboxKitService::class);
    $mock->shouldReceive('createInbox')
        ->once()
        ->andReturn(['data' => ['id' => 50]]);
    $mock->shouldReceive('createWebhook')
        ->once()
        ->andThrow(new RuntimeException('Webhook API down'));
    $this->app->instance(MailboxKitService::class, $mock);

    $team = Team::factory()->starterPlan()->create();
    $agent = Agent::factory()->create(['team_id' => $team->id]);

    $service = app(EmailProvisioningService::class);
    $email = $service->provisionEmail($agent, $team);

    $conn = $agent->fresh()->emailConnection;

    // Email should still be provisioned even when webhook fails
    expect($email)->not->toBeNull()
        ->and($conn)->not->toBeNull()
        ->and((int) $conn->mailboxkit_inbox_id)->toBe(50)
        ->and($conn->mailboxkit_webhook_id)->toBeNull()
        ->and($conn->mailboxkit_webhook_secret)->toBeNull();
});

test('provisionEmail returns null on API failure', function () {
    $mock = Mockery::mock(MailboxKitService::class);
    $mock->shouldReceive('createInbox')
        ->once()
        ->andThrow(new RuntimeException('API down'));
    $this->app->instance(MailboxKitService::class, $mock);

    $team = Team::factory()->starterPlan()->create();
    $agent = Agent::factory()->create(['team_id' => $team->id]);

    $service = app(EmailProvisioningService::class);
    $result = $service->provisionEmail($agent, $team);

    expect($result)->toBeNull()
        ->and($agent->fresh()->emailConnection)->toBeNull();
});

test('injectEmailIntoIdentity inserts email after DOB line', function () {
    $service = app(EmailProvisioningService::class);

    $identity = implode("\n", [
        '# TestBot - Identity',
        '',
        '## Core Identity',
        '- **Name:** TestBot',
        '- **Role:** Researcher',
        '- **DOB:** Feb 28, 2026',
        '',
        '## Communication Philosophy',
    ]);

    $result = $service->injectEmailIntoIdentity($identity, 'test@provisionagents.com');

    expect($result)
        ->toContain("- **DOB:** Feb 28, 2026\n- **Email:** test@provisionagents.com")
        ->toContain('## Communication Philosophy');
});

test('injectEmailIntoIdentity handles identity without DOB line gracefully', function () {
    $service = app(EmailProvisioningService::class);

    $identity = "# TestBot - Identity\n\n## Core Identity\n- **Name:** TestBot";

    $result = $service->injectEmailIntoIdentity($identity, 'test@provisionagents.com');

    // Should return unchanged if no DOB line found
    expect($result)->toBe($identity);
});

test('hired agent identity includes email address', function () {
    $mock = Mockery::mock(MailboxKitService::class);
    $mock->shouldReceive('createInbox')
        ->once()
        ->andReturn(['data' => ['id' => 7]]);
    $mock->shouldReceive('createWebhook')->andReturn(['data' => ['id' => 'wh-7', 'secret' => 'wh-secret']]);
    registerMailboxKitModule($mock);

    $user = User::factory()->withPersonalTeam()->create();
    subscribeEmailTeam($user->currentTeam);
    $template = AgentTemplate::factory()->create([
        'identity' => "# Bot - Identity\n\n## Core Identity\n- **Name:** Bot\n- **DOB:** Feb 28, 2026\n\n## Communication Philosophy\n- Be helpful",
    ]);

    $this->actingAs($user)->post(route('agents.hire', $template));

    $agent = Agent::query()->where('agent_template_id', $template->id)->first();

    expect($agent->identity)->toContain('**Email:**');
});
