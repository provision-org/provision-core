<?php

use App\Contracts\Modules\BillingProvider;
use App\Enums\LlmProvider;
use App\Models\Agent;
use App\Models\AgentEmailConnection;
use App\Models\Server;
use App\Models\TeamApiKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Provision\MailboxKit\Services\MailboxKitService;

uses(RefreshDatabase::class);

function setupEmailTestUser(): array
{
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    subscribeTeam($team, 'pro');
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

    return [$user, $team];
}

function mockMailboxKitForEmail(): void
{
    if (! class_exists(MailboxKitService::class)) {
        return;
    }

    $mock = Mockery::mock(MailboxKitService::class);
    $mock->shouldReceive('createInbox')->andReturn(['data' => ['id' => 1]]);
    $mock->shouldReceive('createWebhook')->andReturn(['data' => ['id' => 1, 'secret' => 'test-secret']]);
    registerMailboxKitModule($mock);
}

test('agent can be created with a custom email prefix', function () {
    if (! class_exists(MailboxKitService::class)) {
        $this->markTestSkipped('MailboxKit module not installed');
    }

    [$user, $team] = setupEmailTestUser();
    mockMailboxKitForEmail();

    $response = $this->actingAs($user)->post(route('agents.store'), [
        'name' => 'Luna',
        'email_prefix' => 'luna.custom',
        'role' => 'custom',
        'model_primary' => 'claude-sonnet-4-6',
    ]);

    $response->assertRedirect();

    $agent = Agent::where('name', 'Luna')->first();
    expect($agent)->not->toBeNull()
        ->and($agent->emailConnection->email_address)->toBe('luna.custom@provisionagents.com');
});

test('agent uses auto-generated email when no prefix provided', function () {
    if (! class_exists(MailboxKitService::class)) {
        $this->markTestSkipped('MailboxKit module not installed');
    }

    [$user, $team] = setupEmailTestUser();
    mockMailboxKitForEmail();

    $this->actingAs($user)->post(route('agents.store'), [
        'name' => 'Luna',
        'role' => 'custom',
        'model_primary' => 'claude-sonnet-4-6',
    ]);

    $agent = Agent::where('name', 'Luna')->first();
    $emailSlug = $agent->emailConnection->email_address;

    expect($emailSlug)->toStartWith('luna_')
        ->and($emailSlug)->toEndWith('@provisionagents.com');
});

test('email prefix must be globally unique', function () {
    if (! class_exists(MailboxKitService::class)) {
        $this->markTestSkipped('MailboxKit module not installed');
    }

    [$user, $team] = setupEmailTestUser();

    $existingAgent = Agent::factory()->create();
    AgentEmailConnection::factory()->create([
        'agent_id' => $existingAgent->id,
        'email_address' => 'luna.custom@provisionagents.com',
        'status' => 'connected',
    ]);

    $response = $this->actingAs($user)->post(route('agents.store'), [
        'name' => 'Luna',
        'email_prefix' => 'luna.custom',
        'role' => 'custom',
        'model_primary' => 'claude-sonnet-4-6',
    ]);

    $response->assertSessionHasErrors('email_prefix');
});

test('precognition validates email prefix uniqueness', function () {
    if (! class_exists(MailboxKitService::class)) {
        $this->markTestSkipped('MailboxKit module not installed');
    }

    [$user] = setupEmailTestUser();

    $existingAgent = Agent::factory()->create();
    AgentEmailConnection::factory()->create([
        'agent_id' => $existingAgent->id,
        'email_address' => 'taken.prefix@provisionagents.com',
        'status' => 'connected',
    ]);

    $response = $this->actingAs($user)
        ->withPrecognition()
        ->postJson(route('agents.store'), [
            'name' => 'Luna',
            'email_prefix' => 'taken.prefix',
            'role' => 'custom',
            'model_primary' => 'claude-sonnet-4-6',
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('email_prefix');
});

test('precognition passes for available email prefix', function () {
    [$user] = setupEmailTestUser();

    $response = $this->actingAs($user)
        ->withPrecognition()
        ->postJson(route('agents.store'), [
            'name' => 'Luna',
            'email_prefix' => 'fresh.prefix',
            'role' => 'custom',
            'model_primary' => 'claude-sonnet-4-6',
        ]);

    $response->assertSuccessfulPrecognition();

    // Agent should NOT be created during precognition
    expect(Agent::where('name', 'Luna')->exists())->toBeFalse();
});

test('email prefix rejects invalid characters', function () {
    [$user] = setupEmailTestUser();

    $response = $this->actingAs($user)->post(route('agents.store'), [
        'name' => 'Luna',
        'email_prefix' => 'bad email!@#',
        'role' => 'custom',
        'model_primary' => 'claude-sonnet-4-6',
    ]);

    $response->assertSessionHasErrors('email_prefix');
});

test('single word email prefix is valid', function () {
    [$user] = setupEmailTestUser();

    $response = $this->actingAs($user)
        ->withPrecognition()
        ->postJson(route('agents.store'), [
            'name' => 'Luna',
            'email_prefix' => 'luna',
            'role' => 'custom',
            'model_primary' => 'claude-sonnet-4-6',
        ]);

    $response->assertSuccessfulPrecognition();
});

test('email prefix cannot end with a special character', function () {
    [$user] = setupEmailTestUser();

    $response = $this->actingAs($user)
        ->withPrecognition()
        ->postJson(route('agents.store'), [
            'name' => 'Luna',
            'email_prefix' => 'luna-',
            'role' => 'custom',
            'model_primary' => 'claude-sonnet-4-6',
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('email_prefix');
});
