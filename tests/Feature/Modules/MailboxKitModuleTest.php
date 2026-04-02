<?php

use App\Contracts\Modules\AgentEmailProvider;
use App\Models\Agent;
use App\Models\AgentEmailConnection;
use App\Models\Team;
use App\Services\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Provision\MailboxKit\MailboxKitModule;
use Provision\MailboxKit\Services\EmailProvisioningService;
use Provision\MailboxKit\Services\MailboxKitService;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (! class_exists(MailboxKitModule::class)) {
        $this->markTestSkipped('MailboxKit module not installed');
    }
});

test('mailboxkit module is registered when api key is configured', function () {
    config(['mailboxkit.api_key' => 'mbk-test-key']);

    $registry = app(ModuleRegistry::class);

    expect($registry->has('mailboxkit'))->toBeTrue()
        ->and($registry->get('mailboxkit'))->toBeInstanceOf(MailboxKitModule::class);
});

test('mailboxkit module provides email capability', function () {
    config(['mailboxkit.api_key' => 'mbk-test-key']);

    $registry = app(ModuleRegistry::class);

    expect($registry->hasCapability('email'))->toBeTrue();
});

test('agent email provider is bound when mailboxkit is configured', function () {
    config(['mailboxkit.api_key' => 'mbk-test-key']);

    expect(app()->bound(AgentEmailProvider::class))->toBeTrue()
        ->and(app(AgentEmailProvider::class))->toBeInstanceOf(MailboxKitModule::class);
});

test('module metadata returns correct values', function () {
    $module = app(ModuleRegistry::class)->get('mailboxkit');

    expect($module->name())->toBe('mailboxkit')
        ->and($module->label())->toBe('MailboxKit Email')
        ->and($module->version())->toBe('1.0.0')
        ->and($module->capabilities())->toBe(['email']);
});

test('shared props returns email domain', function () {
    config(['mailboxkit.email_domain' => 'example.com']);

    $module = app(ModuleRegistry::class)->get('mailboxkit');
    $props = $module->sharedProps(Request::create('/'));

    expect($props)->toHaveKey('emailDomain', 'example.com');
});

test('cleanup agent deletes inbox and webhook', function () {
    Bus::fake();
    $agent = Agent::factory()->create();
    AgentEmailConnection::factory()->create([
        'agent_id' => $agent->id,
        'mailboxkit_inbox_id' => 42,
        'mailboxkit_webhook_id' => 99,
    ]);
    $agent->load('emailConnection');

    $mock = Mockery::mock(MailboxKitService::class);
    $mock->shouldReceive('deleteInbox')->once()->with(42);
    $mock->shouldReceive('deleteWebhook')->once()->with(99);
    $this->app->instance(MailboxKitService::class, $mock);

    $module = new MailboxKitModule(
        app(EmailProvisioningService::class),
        $mock,
    );

    $module->cleanupAgent($agent);
});

test('cleanup agent continues when deletion fails', function () {
    Bus::fake();
    $agent = Agent::factory()->create();
    AgentEmailConnection::factory()->create([
        'agent_id' => $agent->id,
        'mailboxkit_inbox_id' => 42,
        'mailboxkit_webhook_id' => 99,
    ]);
    $agent->load('emailConnection');

    $mock = Mockery::mock(MailboxKitService::class);
    $mock->shouldReceive('deleteInbox')->once()->andThrow(new RuntimeException('API down'));
    $mock->shouldReceive('deleteWebhook')->once()->andThrow(new RuntimeException('API down'));
    $this->app->instance(MailboxKitService::class, $mock);

    $module = new MailboxKitModule(
        app(EmailProvisioningService::class),
        $mock,
    );

    $module->cleanupAgent($agent);

    // Should not throw — errors are logged
    expect(true)->toBeTrue();
});

test('cleanup agent does nothing when no email connection', function () {
    Bus::fake();
    $agent = Agent::factory()->create();

    $mock = Mockery::mock(MailboxKitService::class);
    $mock->shouldNotReceive('deleteInbox');
    $mock->shouldNotReceive('deleteWebhook');

    $module = new MailboxKitModule(
        app(EmailProvisioningService::class),
        $mock,
    );

    $module->cleanupAgent($agent);
});

test('provision email delegates to email provisioning service', function () {
    Bus::fake();
    $agent = Agent::factory()->create();
    $team = Team::factory()->create();

    $emailServiceMock = Mockery::mock(EmailProvisioningService::class);
    $emailServiceMock->shouldReceive('provisionEmail')
        ->once()
        ->with($agent, $team, 'custom-prefix')
        ->andReturn('custom-prefix@example.com');

    $module = new MailboxKitModule(
        $emailServiceMock,
        app(MailboxKitService::class),
    );

    $result = $module->provisionEmail($agent, $team, 'custom-prefix');

    expect($result)->toBe('custom-prefix@example.com');
});

test('get inbox delegates to mailboxkit service', function () {
    Bus::fake();
    $agent = Agent::factory()->create();
    AgentEmailConnection::factory()->create([
        'agent_id' => $agent->id,
        'mailboxkit_inbox_id' => 42,
    ]);
    $agent->load('emailConnection');

    $mock = Mockery::mock(MailboxKitService::class);
    $mock->shouldReceive('listMessages')
        ->once()
        ->with(42, 2)
        ->andReturn(['data' => [], 'meta' => ['current_page' => 2]]);

    $module = new MailboxKitModule(
        app(EmailProvisioningService::class),
        $mock,
    );

    $result = $module->getInbox($agent, 2);

    expect($result)->toHaveKey('data')
        ->and($result['meta']['current_page'])->toBe(2);
});

test('get message delegates to mailboxkit service', function () {
    Bus::fake();
    $agent = Agent::factory()->create();
    AgentEmailConnection::factory()->create([
        'agent_id' => $agent->id,
        'mailboxkit_inbox_id' => 42,
    ]);
    $agent->load('emailConnection');

    $mock = Mockery::mock(MailboxKitService::class);
    $mock->shouldReceive('getMessage')
        ->once()
        ->with(42, 'msg-123')
        ->andReturn(['data' => ['id' => 'msg-123', 'subject' => 'Test']]);

    $module = new MailboxKitModule(
        app(EmailProvisioningService::class),
        $mock,
    );

    $result = $module->getMessage($agent, 'msg-123');

    expect($result['data']['subject'])->toBe('Test');
});
