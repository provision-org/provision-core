<?php

use App\Enums\LlmProvider;
use App\Http\Controllers\AgentController;
use App\Models\Agent;
use App\Models\Server;
use App\Models\Team;
use App\Models\TeamApiKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Provision\MailboxKit\Services\MailboxKitService;

uses(RefreshDatabase::class);

function makeBedrockTeam(bool $aws = true): array
{
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;

    if ($aws) {
        $team->update(['cloud_provider' => 'aws']);
        $team->refresh();
    }

    subscribeTeam($team);
    Server::factory()->running()->create(['team_id' => $team->id]);

    return [$user, $team];
}

function mockBedrockMailboxKit(string $agentName): void
{
    if (! class_exists(MailboxKitService::class)) {
        return;
    }

    $mock = Mockery::mock(MailboxKitService::class);
    $mock->shouldReceive('createInbox')->andReturn([
        'data' => ['id' => 1, 'name' => $agentName, 'email' => 'agent_bedrock@provisionagents.com', 'created_at' => now()->toISOString()],
    ]);
    $mock->shouldReceive('createWebhook')->andReturn(['data' => ['id' => 'wh-1', 'secret' => 'wh-secret']]);
    registerMailboxKitModule($mock);
}

test('creating an agent with the bedrock tier on an AWS team persists bedrock auth and models', function () {
    Bus::fake();
    mockBedrockMailboxKit('Bedrock Agent');
    [$user, $team] = makeBedrockTeam();

    $response = $this->actingAs($user)->post(route('agents.store'), [
        'name' => 'Bedrock Agent',
        'role' => 'custom',
        'model_tier' => 'bedrock',
    ]);

    $response->assertRedirect();
    $response->assertSessionHasNoErrors();

    $agent = Agent::query()->where('name', 'Bedrock Agent')->firstOrFail();

    expect($agent->auth_provider)->toBe('bedrock')
        ->and($agent->model_primary)->toBe('bedrock-claude-sonnet-4-6')
        ->and($agent->model_fallbacks)->toBe(['bedrock-claude-haiku-4-5'])
        ->and($agent->usesBedrock())->toBeTrue();
});

test('bedrock agents resolve their OpenClaw model directly against Bedrock', function () {
    $agent = new Agent([
        'model_primary' => 'bedrock-claude-sonnet-4-6',
        'model_fallbacks' => ['bedrock-claude-haiku-4-5'],
    ]);

    expect($agent->openclawModel())->toBe('amazon-bedrock/us.anthropic.claude-sonnet-4-6')
        ->and($agent->openclawModelConfig())->toBe([
            'primary' => 'amazon-bedrock/us.anthropic.claude-sonnet-4-6',
            'fallbacks' => ['amazon-bedrock/us.anthropic.claude-haiku-4-5-20251001-v1:0'],
        ]);
});

test('bedrock agents heartbeat on Bedrock Haiku with light context', function () {
    $agent = new Agent([
        'auth_provider' => 'bedrock',
        'model_primary' => 'bedrock-claude-sonnet-4-6',
    ]);

    expect($agent->openclawHeartbeatConfig())->toBe([
        'model' => 'amazon-bedrock/us.anthropic.claude-haiku-4-5-20251001-v1:0',
        'lightContext' => true,
    ]);
});

test('non-bedrock, non-chatgpt agents keep the server default heartbeat', function () {
    $agent = new Agent([
        'auth_provider' => 'openrouter',
        'model_primary' => 'claude-sonnet-4-6',
    ]);

    expect($agent->openclawHeartbeatConfig())->toBeNull();
});

test('chatgpt-subscription agents still heartbeat on their own model', function () {
    $agent = new Agent([
        'auth_provider' => 'chatgpt',
        'model_primary' => 'gpt-5.5',
    ]);

    expect($agent->openclawHeartbeatConfig())->toBe([
        'model' => 'openai-codex/gpt-5.5',
        'lightContext' => true,
    ]);
});

test('the bedrock tier is rejected for teams not running on AWS', function () {
    Bus::fake();
    [$user, $team] = makeBedrockTeam(aws: false);

    $response = $this->actingAs($user)->post(route('agents.store'), [
        'name' => 'Bedrock Agent',
        'role' => 'custom',
        'model_tier' => 'bedrock',
    ]);

    $response->assertSessionHasErrors('model_tier');
    expect(Agent::query()->where('name', 'Bedrock Agent')->exists())->toBeFalse();
});

test('allowedModelIds includes bedrock models for AWS teams', function () {
    $team = Team::factory()->aws()->create();

    $allowed = AgentController::allowedModelIds($team);

    expect($allowed)->toContain('bedrock-claude-sonnet-4-6')
        ->and($allowed)->toContain('bedrock-claude-haiku-4-5')
        ->and($allowed)->toContain('bedrock-claude-opus-4-6');
});

test('allowedModelIds excludes bedrock models for non-AWS teams', function () {
    $team = Team::factory()->create(['cloud_provider' => 'hetzner']);
    // Even with an active BYOK key, bedrock stays hidden off-AWS
    TeamApiKey::factory()->create([
        'team_id' => $team->id,
        'provider' => LlmProvider::Anthropic,
        'is_active' => true,
    ]);

    $allowed = AgentController::allowedModelIds($team);

    expect($allowed)->toContain('claude-sonnet-4-6')
        ->and($allowed)->not->toContain('bedrock-claude-sonnet-4-6')
        ->and($allowed)->not->toContain('bedrock-claude-haiku-4-5');
});

test('allowedModelIds excludes bedrock for subscribed non-AWS teams', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    subscribeTeam($team);

    $allowed = AgentController::allowedModelIds($team);

    expect($allowed)->not->toContain('bedrock-claude-sonnet-4-6');
});

test('the AWS cloud credential row never leaks into allowedModelIds', function () {
    $team = Team::factory()->aws()->create();
    TeamApiKey::factory()->awsCloud()->create(['team_id' => $team->id]);

    // Must not throw (cloud providers are not LlmProvider cases) and must
    // still surface the bedrock models for the AWS team.
    $allowed = AgentController::allowedModelIds($team);

    expect($allowed)->toContain('bedrock-claude-sonnet-4-6');
});

test('agents create page passes bedrockAvailable for AWS teams', function () {
    Bus::fake();
    [$user, $team] = makeBedrockTeam();

    $response = $this->actingAs($user)->get(route('agents.create'));

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('agents/create')
        ->where('bedrockAvailable', true)
        ->where('modelTiers', fn ($tiers) => collect($tiers)->pluck('value')->contains('bedrock'))
    );
});

test('agents create page passes bedrockAvailable false off AWS', function () {
    Bus::fake();
    [$user, $team] = makeBedrockTeam(aws: false);

    $response = $this->actingAs($user)->get(route('agents.create'));

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('agents/create')
        ->where('bedrockAvailable', false)
    );
});
