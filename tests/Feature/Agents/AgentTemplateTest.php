<?php

uses()->group('requires-storage');

use App\Contracts\Modules\BillingProvider;
use App\Enums\AgentRole;
use App\Enums\LlmProvider;
use App\Models\Agent;
use App\Models\Server;
use App\Models\Team;
use App\Models\TeamApiKey;
use App\Models\User;
use App\Services\AgentTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Provision\MailboxKit\Services\MailboxKitService;

uses(RefreshDatabase::class);

function subscribeTemplateTeam(Team $team): void
{
    subscribeTeam($team);
    Server::factory()->running()->create(['team_id' => $team->id]);

    // When billing is not installed, subscribeTeam is a no-op.
    // Add a BYOK API key so the team has access to Anthropic models for validation.
    if (! app()->bound(BillingProvider::class)) {
        TeamApiKey::factory()->create([
            'team_id' => $team->id,
            'provider' => LlmProvider::Anthropic,
            'is_active' => true,
        ]);
    }
}

test('template endpoint returns data for valid role', function () {
    $user = User::factory()->withPersonalTeam()->create();

    $response = $this->actingAs($user)->getJson(route('agents.template', 'bdr'));

    $response->assertOk()
        ->assertJsonStructure(['soul', 'system_prompt', 'tools_config', 'user_context']);
});

test('template endpoint returns 404 for invalid role', function () {
    $user = User::factory()->withPersonalTeam()->create();

    $response = $this->actingAs($user)->getJson(route('agents.template', 'nonexistent'));

    $response->assertNotFound();
});

test('custom role returns user context from profile', function () {
    $user = User::factory()->withCompletedProfile()->withPersonalTeam()->create();

    $response = $this->actingAs($user)->getJson(route('agents.template', 'custom'));

    $response->assertOk()
        ->assertJson([
            'soul' => '',
            'system_prompt' => '',
            'tools_config' => '',
        ]);

    $data = $response->json();
    expect($data['user_context'])->toContain($user->name)
        ->and($data['user_context'])->toContain('USER.md');
});

test('template endpoint returns user context from profile', function () {
    $user = User::factory()->withCompletedProfile()->withPersonalTeam()->create();
    $user->currentTeam->update(['company_name' => 'Test Corp']);

    $response = $this->actingAs($user)->getJson(route('agents.template', 'bdr'));

    $data = $response->json();
    expect($data['user_context'])->toContain($user->name)
        ->and($data['user_context'])->toContain('Test Corp');
});

test('template merges base agents.md with role-specific agents.md', function () {
    $templatePath = storage_path('app/agent-templates/bdr/agents.md');
    if (! file_exists($templatePath)) {
        $this->markTestSkipped('Agent template files not present on disk');
    }

    $service = app(AgentTemplateService::class);

    $template = $service->getTemplate(AgentRole::Bdr);

    expect($template['system_prompt'])->toContain('## Mission')
        ->and($template['system_prompt'])->toContain('## Every Session')
        ->and($template['system_prompt'])->toContain('## Memory')
        ->and($template['system_prompt'])->toContain('## Safety');
});

test('template returns empty tools_config', function () {
    $service = app(AgentTemplateService::class);

    $template = $service->getTemplate(AgentRole::Bdr);

    expect($template['tools_config'])->toBe('');
});

test('agent store persists workspace fields', function () {
    Bus::fake();
    if (class_exists(MailboxKitService::class)) {
        $mock = Mockery::mock(MailboxKitService::class);
        $mock->shouldReceive('createInbox')
            ->once()
            ->andReturn(['data' => ['id' => 1, 'name' => 'Test Agent', 'email' => 'agent_test_agent@provisionagents.com', 'created_at' => now()->toISOString()]]);
        $mock->shouldReceive('createWebhook')->andReturn(['data' => ['id' => 'wh-1', 'secret' => 'wh-secret']]);
        registerMailboxKitModule($mock);
    }

    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    subscribeTemplateTeam($team);

    $this->actingAs($user)->post(route('agents.store'), [
        'name' => 'Test Agent',
        'role' => 'bdr',
        'model_primary' => 'claude-opus-4-6',
        'soul' => 'Test soul content',
        'tools_config' => 'Test tools content',
        'user_context' => 'Test user context',
        'system_prompt' => 'Test system prompt',
    ]);

    $agent = Agent::where('name', 'Test Agent')->first();

    expect($agent)->not->toBeNull()
        ->and($agent->role)->toBe(AgentRole::Bdr)
        ->and($agent->soul)->toBe('Test soul content')
        ->and($agent->tools_config)->toBe('Test tools content')
        ->and($agent->user_context)->toBe('Test user context')
        ->and($agent->system_prompt)->toBe('Test system prompt');
});

test('agent store validates role field', function () {
    Bus::fake();
    $user = User::factory()->withPersonalTeam()->create();
    subscribeTemplateTeam($user->currentTeam);

    $response = $this->actingAs($user)->post(route('agents.store'), [
        'name' => 'Test Agent',
        'role' => 'invalid_role',
    ]);

    $response->assertSessionHasErrors(['role']);
});

test('agent store auto-generates identity when empty', function () {
    Bus::fake();
    if (class_exists(MailboxKitService::class)) {
        $mock = Mockery::mock(MailboxKitService::class);
        $mock->shouldReceive('createInbox')
            ->once()
            ->andReturn(['data' => ['id' => 1, 'name' => 'Sales Bot', 'email' => 'agent_sales_bot@provisionagents.com', 'created_at' => now()->toISOString()]]);
        $mock->shouldReceive('createWebhook')->andReturn(['data' => ['id' => 'wh-1', 'secret' => 'wh-secret']]);
        registerMailboxKitModule($mock);
    }

    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    subscribeTemplateTeam($team);

    $this->actingAs($user)->post(route('agents.store'), [
        'name' => 'Sales Bot',
        'role' => 'custom',
        'model_primary' => 'claude-opus-4-6',
    ]);

    $agent = Agent::where('name', 'Sales Bot')->first();

    expect($agent->identity)->toContain('Sales Bot')
        ->and($agent->identity)->toContain('Communication Philosophy')
        ->and($agent->identity)->toContain('Boundaries');

    if (class_exists(MailboxKitService::class)) {
        expect($agent->identity)->toContain('**Email:**')
            ->and($agent->identity)->toContain('@provisionagents.com');
    }
});

test('agent store with personality fields generates rich identity', function () {
    Bus::fake();
    if (class_exists(MailboxKitService::class)) {
        $mock = Mockery::mock(MailboxKitService::class);
        $mock->shouldReceive('createInbox')
            ->once()
            ->andReturn(['data' => ['id' => 1, 'name' => 'Luna', 'email' => 'agent_luna@provisionagents.com', 'created_at' => now()->toISOString()]]);
        $mock->shouldReceive('createWebhook')->andReturn(['data' => ['id' => 'wh-1', 'secret' => 'wh-secret']]);
        registerMailboxKitModule($mock);
    }

    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    subscribeTemplateTeam($team);

    $this->actingAs($user)->post(route('agents.store'), [
        'name' => 'Luna',
        'role' => 'bdr',
        'model_primary' => 'claude-opus-4-6',
        'emoji' => "\u{1F98A}",
        'personality' => 'Curious & nerdy',
        'communication_style' => 'Playful & witty',
        'backstory' => 'I ask thought-provoking questions before working on tasks.',
    ]);

    $agent = Agent::where('name', 'Luna')->first();

    expect($agent->identity)->toContain("Luna \u{1F98A} - Identity")
        ->and($agent->identity)->toContain('**Emoji:**')
        ->and($agent->identity)->toContain('**Personality:** Curious & nerdy')
        ->and($agent->identity)->toContain('**Style:** Playful & witty')
        ->and($agent->identity)->toContain('**Role:** BDR')
        ->and($agent->identity)->toContain('## Backstory')
        ->and($agent->identity)->toContain('thought-provoking questions');

    if (class_exists(MailboxKitService::class)) {
        expect($agent->identity)->toContain('**Email:**')
            ->and($agent->identity)->toContain('@provisionagents.com');
    }
});

test('agent store auto-generates user context from profile when empty', function () {
    Bus::fake();
    if (class_exists(MailboxKitService::class)) {
        $mock = Mockery::mock(MailboxKitService::class);
        $mock->shouldReceive('createInbox')
            ->once()
            ->andReturn(['data' => ['id' => 1, 'name' => 'Bot', 'email' => 'agent_bot@provisionagents.com', 'created_at' => now()->toISOString()]]);
        $mock->shouldReceive('createWebhook')->andReturn(['data' => ['id' => 'wh-1', 'secret' => 'wh-secret']]);
        registerMailboxKitModule($mock);
    }

    $user = User::factory()->withCompletedProfile()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $team->update(['company_name' => 'Bot Corp']);
    subscribeTemplateTeam($team);

    $this->actingAs($user)->post(route('agents.store'), [
        'name' => 'Bot',
        'role' => 'bdr',
        'model_primary' => 'claude-opus-4-6',
    ]);

    $agent = Agent::where('name', 'Bot')->first();

    expect($agent->user_context)->toContain($user->name)
        ->and($agent->user_context)->toContain('Bot Corp')
        ->and($agent->user_context)->toContain('USER.md');
});

test('agent update persists workspace fields', function () {
    Bus::fake();
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $agent = Agent::factory()->create(['team_id' => $team->id]);

    $this->actingAs($user)->patch(route('agents.update', $agent), [
        'soul' => 'Updated soul',
        'tools_config' => 'Updated tools',
        'user_context' => 'Updated context',
    ]);

    $agent->refresh();

    expect($agent->soul)->toBe('Updated soul')
        ->and($agent->tools_config)->toBe('Updated tools')
        ->and($agent->user_context)->toBe('Updated context');
});

test('template service generates identity with name only', function () {
    $service = app(AgentTemplateService::class);

    $identity = $service->generateIdentity('Sales Bot');

    expect($identity)->toContain('# Sales Bot - Identity')
        ->and($identity)->toContain('**Name:** Sales Bot')
        ->and($identity)->not->toContain('Email')
        ->and($identity)->not->toContain('Role')
        ->and($identity)->toContain('Communication Philosophy')
        ->and($identity)->toContain('Boundaries');
});

test('template service generates identity with name and email', function () {
    $service = app(AgentTemplateService::class);

    $identity = $service->generateIdentity('Sales Bot', email: 'sales@example.com');

    expect($identity)->toContain('# Sales Bot - Identity')
        ->and($identity)->toContain('**Name:** Sales Bot')
        ->and($identity)->toContain('sales@example.com');
});

test('template service generates identity with role', function () {
    $service = app(AgentTemplateService::class);

    $identity = $service->generateIdentity('Sales Bot', AgentRole::Bdr, 'sales@example.com');

    expect($identity)->toContain('# Sales Bot - Identity')
        ->and($identity)->toContain('**Name:** Sales Bot')
        ->and($identity)->toContain('**Role:** BDR')
        ->and($identity)->toContain('sales@example.com');
});

test('template service generates identity with personality fields', function () {
    $service = app(AgentTemplateService::class);

    $identity = $service->generateIdentity(
        name: 'Luna',
        role: AgentRole::Bdr,
        email: 'luna@example.com',
        emoji: "\u{1F98A}",
        personality: 'Curious & nerdy',
        style: 'Playful & witty',
        backstory: 'I love exploring new ideas.',
    );

    expect($identity)->toContain("# Luna \u{1F98A} - Identity")
        ->and($identity)->toContain("**Emoji:** \u{1F98A}")
        ->and($identity)->toContain('**Personality:** Curious & nerdy')
        ->and($identity)->toContain('**Style:** Playful & witty')
        ->and($identity)->toContain('**Role:** BDR')
        ->and($identity)->toContain('luna@example.com')
        ->and($identity)->toContain('## Backstory')
        ->and($identity)->toContain('I love exploring new ideas.');
});

test('template service generates user context from profile and team', function () {
    $user = User::factory()->withCompletedProfile()->withPersonalTeam()->create();
    $user->currentTeam->update(['company_name' => 'Acme Corp', 'company_url' => 'https://acme.com']);
    $service = app(AgentTemplateService::class);

    $context = $service->generateUserContext($user, $user->currentTeam);

    expect($context)->toContain('# USER.md - About Your Human')
        ->and($context)->toContain($user->name)
        ->and($context)->toContain('Acme Corp')
        ->and($context)->toContain('https://acme.com')
        ->and($context)->toContain($user->timezone);
});

test('template service returns available roles', function () {
    $service = app(AgentTemplateService::class);

    $roles = $service->availableRoles();

    expect($roles)->toBeArray()
        ->and(count($roles))->toBe(count(AgentRole::cases()));

    $values = array_column($roles, 'value');
    expect($values)->toContain('bdr')
        ->and($values)->toContain('custom')
        ->and($values)->toContain('backend_developer');
});
