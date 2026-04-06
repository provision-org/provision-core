<?php

use App\Contracts\Modules\BillingProvider;
use App\Enums\AgentRole;
use App\Enums\LlmProvider;
use App\Exceptions\SlackApiException;
use App\Jobs\GenerateAgentAvatarJob;
use App\Models\Agent;
use App\Models\AgentSlackConnection;
use App\Models\AgentTemplate;
use App\Models\Server;
use App\Models\Team;
use App\Models\TeamApiKey;
use App\Models\User;
use App\Services\ReplicateService;
use App\Services\SlackApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Provision\MailboxKit\Services\MailboxKitService;

uses(RefreshDatabase::class);

function subscribeAvatarTeam(Team $team): void
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

test('job generates avatar and saves to storage', function () {
    Storage::fake('public');
    config(['replicate.api_token' => 'test-token']);

    $agent = Agent::factory()->researcher()->create();

    $mock = Mockery::mock(ReplicateService::class);
    $mock->shouldReceive('generateAvatar')
        ->once()
        ->withArgs(fn (string $prompt) => str_contains($prompt, 'golden ratio'))
        ->andReturn('https://replicate.delivery/test/avatar.jpg');

    Http::fake([
        'replicate.delivery/*' => Http::response('fake-image-data', 200),
    ]);

    $this->app->instance(ReplicateService::class, $mock);

    $slackApi = app(SlackApiService::class);

    (new GenerateAgentAvatarJob($agent))->handle($mock, $slackApi);

    Storage::disk('public')->assertExists("avatars/{$agent->id}.jpg");
    expect($agent->fresh()->avatar_path)->toBe("avatars/{$agent->id}.jpg");
});

test('job skips when no replicate api token configured', function () {
    config(['replicate.api_token' => null]);

    $agent = Agent::factory()->bdr()->create();

    $mock = Mockery::mock(ReplicateService::class);
    $mock->shouldNotReceive('generateAvatar');
    $this->app->instance(ReplicateService::class, $mock);

    $slackApi = app(SlackApiService::class);

    (new GenerateAgentAvatarJob($agent))->handle($mock, $slackApi);

    expect($agent->fresh()->avatar_path)->toBeNull();
});

test('job uses correct prompt for each role', function () {
    Storage::fake('public');
    config(['replicate.api_token' => 'test-token']);

    $agent = Agent::factory()->bdr()->create();

    $capturedPrompt = null;
    $mock = Mockery::mock(ReplicateService::class);
    $mock->shouldReceive('generateAvatar')
        ->once()
        ->withArgs(function (string $prompt) use (&$capturedPrompt) {
            $capturedPrompt = $prompt;

            return true;
        })
        ->andReturn('https://replicate.delivery/test/bdr-avatar.jpg');

    Http::fake([
        'replicate.delivery/*' => Http::response('fake-image-data', 200),
    ]);

    $slackApi = app(SlackApiService::class);

    (new GenerateAgentAvatarJob($agent))->handle($mock, $slackApi);

    expect($capturedPrompt)
        ->toContain('emerald green and gold')
        ->toContain('Abstract geometric avatar');
});

test('job is dispatched when creating agent via store', function () {
    Bus::fake([GenerateAgentAvatarJob::class]);

    if (class_exists(MailboxKitService::class)) {
        $mailboxKit = Mockery::mock(MailboxKitService::class);
        $mailboxKit->shouldReceive('createInbox')->once()->andReturn(['data' => ['id' => 1]]);
        $mailboxKit->shouldReceive('createWebhook')->andReturn(['data' => ['id' => 'wh-1', 'secret' => 'wh-secret']]);
        registerMailboxKitModule($mailboxKit);
    }

    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    subscribeAvatarTeam($team);

    $this->actingAs($user)->post(route('agents.store'), [
        'name' => 'Avatar Test Agent',
        'role' => 'researcher',
        'model_primary' => 'claude-opus-4-6',
    ]);

    Bus::assertDispatched(GenerateAgentAvatarJob::class);
});

test('job is dispatched when hiring agent from template', function () {
    Bus::fake([GenerateAgentAvatarJob::class]);

    if (class_exists(MailboxKitService::class)) {
        $mailboxKit = Mockery::mock(MailboxKitService::class);
        $mailboxKit->shouldReceive('createInbox')->once()->andReturn(['data' => ['id' => 2]]);
        $mailboxKit->shouldReceive('createWebhook')->andReturn(['data' => ['id' => 'wh-2', 'secret' => 'wh-secret']]);
        registerMailboxKitModule($mailboxKit);
    }

    $user = User::factory()->withPersonalTeam()->create();
    subscribeAvatarTeam($user->currentTeam);
    $template = AgentTemplate::factory()->projectManager()->create();

    $this->actingAs($user)->post(route('agents.hire', $template));

    Bus::assertDispatched(GenerateAgentAvatarJob::class);
});

test('job syncs avatar to Slack when bot is connected', function () {
    Storage::fake('public');
    config(['replicate.api_token' => 'test-token']);

    $agent = Agent::factory()->researcher()->create();
    AgentSlackConnection::factory()->create([
        'agent_id' => $agent->id,
        'bot_token' => 'xoxb-test-token',
    ]);

    $replicateMock = Mockery::mock(ReplicateService::class);
    $replicateMock->shouldReceive('generateAvatar')->once()->andReturn('https://replicate.delivery/test/avatar.jpg');

    $slackMock = Mockery::mock(SlackApiService::class);
    $slackMock->shouldReceive('setBotPhoto')
        ->once()
        ->withArgs(fn (string $token, string $image) => $token === 'xoxb-test-token' && $image === 'fake-image-data');

    Http::fake([
        'replicate.delivery/*' => Http::response('fake-image-data', 200),
    ]);

    (new GenerateAgentAvatarJob($agent))->handle($replicateMock, $slackMock);

    Storage::disk('public')->assertExists("avatars/{$agent->id}.jpg");
});

test('job continues when Slack sync fails', function () {
    Storage::fake('public');
    config(['replicate.api_token' => 'test-token']);

    $agent = Agent::factory()->researcher()->create();
    AgentSlackConnection::factory()->create([
        'agent_id' => $agent->id,
        'bot_token' => 'xoxb-test-token',
    ]);

    $replicateMock = Mockery::mock(ReplicateService::class);
    $replicateMock->shouldReceive('generateAvatar')->once()->andReturn('https://replicate.delivery/test/avatar.jpg');

    $slackMock = Mockery::mock(SlackApiService::class);
    $slackMock->shouldReceive('setBotPhoto')->once()->andThrow(new SlackApiException('Failed', 'invalid_token'));

    Http::fake([
        'replicate.delivery/*' => Http::response('fake-image-data', 200),
    ]);

    (new GenerateAgentAvatarJob($agent))->handle($replicateMock, $slackMock);

    // Avatar should still be saved even if Slack sync fails
    Storage::disk('public')->assertExists("avatars/{$agent->id}.jpg");
    expect($agent->fresh()->avatar_path)->toBe("avatars/{$agent->id}.jpg");
});

test('each agent role has an avatar prompt', function () {
    foreach (AgentRole::cases() as $role) {
        $prompt = $role->avatarPrompt();

        expect($prompt)
            ->toBeString()
            ->toContain('Abstract geometric avatar')
            ->toContain('1024x1024');
    }
});
