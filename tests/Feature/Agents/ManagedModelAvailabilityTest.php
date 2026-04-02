<?php

use App\Enums\LlmProvider;
use App\Http\Controllers\AgentController;
use App\Models\TeamApiKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Provision\Billing\Models\ManagedOpenRouterKey;
use Provision\MailboxKit\Services\MailboxKitService;

uses(RefreshDatabase::class);

function createSubscribedTeam(): array
{
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    subscribeTeam($team);

    return [$user, $team];
}

test('subscribed team sees all managed models on create page', function () {
    Bus::fake();
    [$user, $team] = createSubscribedTeam();

    $response = $this->actingAs($user)->get(route('agents.create'));

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('agents/create')
        ->where('availableModels', fn ($models) => collect($models)->pluck('value')->contains('claude-opus-4-6'))
    );
});

test('subscribed team with managed key sees models', function () {
    Bus::fake();
    [$user, $team] = createSubscribedTeam();
    ManagedOpenRouterKey::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)->get(route('agents.create'));

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->where('availableModels', fn ($models) => count($models) > 0)
    );
});

test('unsubscribed team without keys sees no models', function () {
    Bus::fake();
    $user = User::factory()->withPersonalTeam()->create();

    $response = $this->actingAs($user)->get(route('agents.create'));

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->where('availableModels', [])
    );
});

test('team with byok keys still sees byok models', function () {
    Bus::fake();
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    TeamApiKey::factory()->create(['team_id' => $team->id, 'provider' => LlmProvider::Anthropic]);

    $response = $this->actingAs($user)->get(route('agents.create'));

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->where('availableModels', fn ($models) => collect($models)->pluck('value')->contains('claude-opus-4-6'))
    );
});

test('managed models and byok models are merged without duplicates', function () {
    Bus::fake();
    [$user, $team] = createSubscribedTeam();
    // Also add a BYOK Anthropic key — models overlap with managed models
    TeamApiKey::factory()->create(['team_id' => $team->id, 'provider' => LlmProvider::Anthropic]);

    $response = $this->actingAs($user)->get(route('agents.create'));

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->where('availableModels', function ($models) {
            $values = collect($models)->pluck('value');

            // No duplicates
            return $values->count() === $values->unique()->count();
        })
    );
});

test('allowedModelIds includes all models for subscribed team', function () {
    Bus::fake();
    [$user, $team] = createSubscribedTeam();

    $allowed = AgentController::allowedModelIds($team);

    expect($allowed)->toContain('claude-opus-4-6');
});

test('subscribed team can create agent with managed model', function () {
    Bus::fake();
    if (class_exists(MailboxKitService::class)) {
        $mock = Mockery::mock(MailboxKitService::class);
        $mock->shouldReceive('createInbox')->once()->andReturn([
            'data' => ['id' => 1, 'name' => 'Atlas', 'email' => 'agent_atlas@provisionagents.com', 'created_at' => now()->toISOString()],
        ]);
        $mock->shouldReceive('createWebhook')->andReturn(['data' => ['id' => 'wh-1', 'secret' => 'wh-secret']]);
        registerMailboxKitModule($mock);
    }

    [$user, $team] = createSubscribedTeam();

    $response = $this->actingAs($user)->post(route('agents.store'), [
        'name' => 'Atlas',
        'role' => 'custom',
        'model_primary' => 'claude-opus-4-6',
    ]);

    $response->assertRedirect();
    $this->assertDatabaseHas('agents', [
        'name' => 'Atlas',
        'model_primary' => 'claude-opus-4-6',
    ]);
});
