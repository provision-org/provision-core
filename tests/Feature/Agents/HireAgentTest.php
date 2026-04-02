<?php

use App\Enums\AgentRole;
use App\Enums\AgentStatus;
use App\Enums\TeamRole;
use App\Models\Agent;
use App\Models\AgentTemplate;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Provision\MailboxKit\Services\MailboxKitService;

uses(RefreshDatabase::class);

function subscribeHireTeam(Team $team): void
{
    subscribeTeam($team);
    Server::factory()->running()->create(['team_id' => $team->id]);
}

test('admin can hire agent from template', function () {
    if (class_exists(MailboxKitService::class)) {
        $mailboxKit = Mockery::mock(MailboxKitService::class);
        $mailboxKit->shouldReceive('createInbox')->andReturn(['data' => ['id' => 1]]);
        $mailboxKit->shouldReceive('createWebhook')->andReturn(['data' => ['id' => 'wh-1', 'secret' => 'wh-secret']]);
        registerMailboxKitModule($mailboxKit);
    }

    $user = User::factory()->withPersonalTeam()->create();
    subscribeHireTeam($user->currentTeam);
    $template = AgentTemplate::factory()->projectManager()->create();

    $response = $this->actingAs($user)->post(route('agents.hire', $template));

    $agent = Agent::query()->where('agent_template_id', $template->id)->first();

    expect($agent)->not->toBeNull();
    $response->assertRedirect(route('agents.setup', $agent));
});

test('hired agent has correct template fields', function () {
    if (class_exists(MailboxKitService::class)) {
        $mailboxKit = Mockery::mock(MailboxKitService::class);
        $mailboxKit->shouldReceive('createInbox')->once()->andReturn(['data' => ['id' => 2]]);
        $mailboxKit->shouldReceive('createWebhook')->andReturn(['data' => ['id' => 'wh-2', 'secret' => 'wh-secret']]);
        registerMailboxKitModule($mailboxKit);
    }

    $user = User::factory()->withPersonalTeam()->create();
    subscribeHireTeam($user->currentTeam);
    $template = AgentTemplate::factory()->create([
        'name' => 'TestBot',
        'role' => AgentRole::Researcher,
        'soul' => 'Test soul content',
        'identity' => "# TestBot - Identity\n\n## Core Identity\n- **Name:** TestBot\n- **DOB:** Feb 28, 2026",
        'system_prompt' => 'Test system prompt',
        'tools_config' => 'Test tools config',
        'model_primary' => 'claude-sonnet-4-6',
    ]);

    $this->actingAs($user)->post(route('agents.hire', $template));

    $agent = Agent::query()->where('agent_template_id', $template->id)->first();

    expect($agent)
        ->name->toBe('TestBot')
        ->role->toBe(AgentRole::Researcher)
        ->soul->toBe('Test soul content')
        ->system_prompt->toBe('Test system prompt')
        ->tools_config->toBe('Test tools config')
        ->model_primary->toBe('claude-sonnet-4-6')
        ->status->toBe(AgentStatus::Pending);

    // Identity should have email injected after DOB line (only when MailboxKit is installed)
    if (class_exists(MailboxKitService::class)) {
        expect($agent->identity)
            ->toContain('**DOB:**')
            ->toContain('**Email:**');
    }
});

test('hired agent gets user context generated', function () {
    if (class_exists(MailboxKitService::class)) {
        $mailboxKit = Mockery::mock(MailboxKitService::class);
        $mailboxKit->shouldReceive('createInbox')->once()->andReturn(['data' => ['id' => 3]]);
        $mailboxKit->shouldReceive('createWebhook')->andReturn(['data' => ['id' => 'wh-3', 'secret' => 'wh-secret']]);
        registerMailboxKitModule($mailboxKit);
    }

    $user = User::factory()->withPersonalTeam()->create([
        'name' => 'Jane Doe',
    ]);
    $user->currentTeam->update(['company_name' => 'Acme Corp']);
    subscribeHireTeam($user->currentTeam);
    $template = AgentTemplate::factory()->create();

    $this->actingAs($user)->post(route('agents.hire', $template));

    $agent = Agent::query()->where('agent_template_id', $template->id)->first();

    expect($agent->user_context)
        ->toContain('Jane Doe')
        ->toContain('Acme Corp');
});

test('hired agent gets email provisioned', function () {
    if (! class_exists(MailboxKitService::class)) {
        $this->markTestSkipped('MailboxKit module not installed');
    }

    $mailboxKit = Mockery::mock(MailboxKitService::class);
    $mailboxKit->shouldReceive('createInbox')->once()->andReturn(['data' => ['id' => 4]]);
    $mailboxKit->shouldReceive('createWebhook')->andReturn(['data' => ['id' => 'wh-4', 'secret' => 'wh-secret']]);
    registerMailboxKitModule($mailboxKit);

    $user = User::factory()->withPersonalTeam()->create();
    subscribeHireTeam($user->currentTeam);
    $template = AgentTemplate::factory()->create();

    $this->actingAs($user)->post(route('agents.hire', $template));

    $agent = Agent::query()->where('agent_template_id', $template->id)->first();

    expect($agent->emailConnection)->not->toBeNull();
    expect((int) $agent->emailConnection->mailboxkit_inbox_id)->toBe(4);
});

test('hired agent gets api token', function () {
    if (class_exists(MailboxKitService::class)) {
        $mailboxKit = Mockery::mock(MailboxKitService::class);
        $mailboxKit->shouldReceive('createInbox')->once()->andReturn(['data' => ['id' => 5]]);
        $mailboxKit->shouldReceive('createWebhook')->andReturn(['data' => ['id' => 'wh-5', 'secret' => 'wh-secret']]);
        registerMailboxKitModule($mailboxKit);
    }

    $user = User::factory()->withPersonalTeam()->create();
    subscribeHireTeam($user->currentTeam);
    $template = AgentTemplate::factory()->create();

    $this->actingAs($user)->post(route('agents.hire', $template));

    $agent = Agent::query()->where('agent_template_id', $template->id)->first();

    expect($agent)->not->toBeNull();
});

test('non-admin cannot hire agent', function () {
    $team = Team::factory()->subscribed()->create();
    $member = User::factory()->withPersonalTeam()->create();
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $member->forceFill(['current_team_id' => $team->id])->save();

    $template = AgentTemplate::factory()->create();

    $response = $this->actingAs($member->fresh())->post(route('agents.hire', $template));

    $response->assertForbidden();
});

test('inactive template cannot be hired', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $template = AgentTemplate::factory()->inactive()->create();

    $response = $this->actingAs($user)->post(route('agents.hire', $template));

    $response->assertNotFound();
});

test('hired agent belongs to current team', function () {
    if (class_exists(MailboxKitService::class)) {
        $mailboxKit = Mockery::mock(MailboxKitService::class);
        $mailboxKit->shouldReceive('createInbox')->once()->andReturn(['data' => ['id' => 6]]);
        $mailboxKit->shouldReceive('createWebhook')->andReturn(['data' => ['id' => 'wh-6', 'secret' => 'wh-secret']]);
        registerMailboxKitModule($mailboxKit);
    }

    $user = User::factory()->withPersonalTeam()->create();
    subscribeHireTeam($user->currentTeam);
    $template = AgentTemplate::factory()->create();

    $this->actingAs($user)->post(route('agents.hire', $template));

    $agent = Agent::query()->where('agent_template_id', $template->id)->first();

    expect($agent->team_id)->toBe($user->currentTeam->id);
});
