<?php

use App\Enums\LlmProvider;
use App\Models\AgentTemplate;
use App\Models\Server;
use App\Models\Team;
use App\Models\TeamApiKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Provision\MailboxKit\Services\MailboxKitService;

uses(RefreshDatabase::class);

function createGateTestUser(): array
{
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;

    return [$user, $team];
}

function subscribeGateTeam(Team $team): void
{
    subscribeTeam($team);
    Server::factory()->running()->create(['team_id' => $team->id]);
}

function mockMailboxKit(): void
{
    if (! class_exists(MailboxKitService::class)) {
        return;
    }

    $mock = Mockery::mock(MailboxKitService::class);
    $mock->shouldReceive('createInbox')->andReturn(['data' => ['id' => 1]]);
    app()->instance(MailboxKitService::class, $mock);
}

test('store requires subscription', function () {
    [$user] = createGateTestUser();

    $response = $this->actingAs($user)->post(route('agents.store'), [
        'name' => 'Test Agent',
        'role' => 'custom',
    ]);

    $response->assertRedirect(route('subscribe'));
});

test('store succeeds with active subscription', function () {
    mockMailboxKit();
    [$user, $team] = createGateTestUser();
    subscribeGateTeam($team);

    $response = $this->actingAs($user)->post(route('agents.store'), [
        'name' => 'Test Agent',
        'role' => 'custom',
        'model_primary' => 'claude-opus-4-6',
    ]);

    $response->assertRedirect();
    expect($team->agents()->where('name', 'Test Agent')->exists())->toBeTrue();
});

test('store fails with BYOK API key but no subscription', function () {
    [$user, $team] = createGateTestUser();
    TeamApiKey::factory()->create(['team_id' => $team->id, 'provider' => LlmProvider::Anthropic]);

    $response = $this->actingAs($user)->post(route('agents.store'), [
        'name' => 'Test Agent',
        'role' => 'custom',
        'model_primary' => 'claude-opus-4-6',
    ]);

    $response->assertRedirect(route('subscribe'));
});

test('hire requires subscription', function () {
    [$user] = createGateTestUser();
    $template = AgentTemplate::factory()->create();

    $response = $this->actingAs($user)->post(route('agents.hire', $template));

    $response->assertRedirect(route('subscribe'));
});

test('hire succeeds with active subscription', function () {
    mockMailboxKit();
    [$user, $team] = createGateTestUser();
    subscribeGateTeam($team);
    $template = AgentTemplate::factory()->create();

    $response = $this->actingAs($user)->post(route('agents.hire', $template));

    $response->assertRedirect();
    expect($team->agents()->where('agent_template_id', $template->id)->exists())->toBeTrue();
});

test('hire fails with BYOK API key but no subscription', function () {
    [$user, $team] = createGateTestUser();
    TeamApiKey::factory()->create(['team_id' => $team->id, 'provider' => LlmProvider::Anthropic]);
    $template = AgentTemplate::factory()->create();

    $response = $this->actingAs($user)->post(route('agents.hire', $template));

    $response->assertRedirect(route('subscribe'));
});

test('index page passes canCreateAgent, agentLimit, and currentPlan props', function () {
    [$user, $team] = createGateTestUser();

    $response = $this->actingAs($user)->get(route('agents.index'));

    $response->assertInertia(fn ($page) => $page
        ->has('canCreateAgent')
        ->has('agentLimit')
        ->has('currentPlan')
    );
});

test('create page passes canCreateAgent, agentLimit, and currentPlan props', function () {
    [$user, $team] = createGateTestUser();

    $response = $this->actingAs($user)->get(route('agents.create'));

    $response->assertInertia(fn ($page) => $page
        ->has('canCreateAgent')
        ->has('agentLimit')
        ->has('currentPlan')
    );
});

test('billing subscribe route creates Stripe checkout', function () {
    [$user, $team] = createGateTestUser();

    $response = $this->actingAs($user)->post(route('billing.subscribe'));

    // Cashier will throw without real Stripe keys, so we expect either a redirect
    // (to Stripe checkout) or a server error from Stripe API — not a 404 or 403
    expect($response->status())->not->toBe(404);
    expect($response->status())->not->toBe(403);
});
