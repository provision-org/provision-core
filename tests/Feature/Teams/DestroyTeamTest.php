<?php

use App\Enums\CloudProvider;
use App\Enums\TeamRole;
use App\Jobs\DestroyTeamJob;
use App\Models\Agent;
use App\Models\AgentEmailConnection;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\CloudServiceFactory;
use App\Services\DigitalOceanService;
use App\Services\SlackAppCleanupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Provision\Billing\Models\ManagedOpenRouterKey;
use Provision\Billing\Services\OpenRouterProvisioningService;
use Provision\MailboxKit\Services\MailboxKitService;

uses(RefreshDatabase::class);

it('dispatches DestroyTeamJob when team owner deletes team', function () {
    Queue::fake();

    $user = User::factory()->withPersonalTeam()->create();
    $team = Team::factory()->subscribed()->create(['user_id' => $user->id]);
    $team->members()->attach($user, ['role' => TeamRole::Admin->value]);
    $user->switchTeam($team);

    $this->actingAs($user)
        ->delete(route('teams.destroy', $team))
        ->assertRedirect();

    Queue::assertPushed(DestroyTeamJob::class, fn ($job) => $job->team->id === $team->id);
});

it('cleans up mailboxkit inboxes and webhooks when destroying team', function () {
    if (! class_exists(MailboxKitService::class)) {
        $this->markTestSkipped('MailboxKit module not installed');
    }

    $team = Team::factory()->subscribed()->create();
    $agent = Agent::factory()->create(['team_id' => $team->id]);
    $emailConnection = AgentEmailConnection::factory()->create([
        'agent_id' => $agent->id,
        'mailboxkit_inbox_id' => 12345,
        'mailboxkit_webhook_id' => 67890,
    ]);

    $mailboxKit = Mockery::mock(MailboxKitService::class);
    $mailboxKit->shouldReceive('deleteInbox')->with(12345)->once();
    $mailboxKit->shouldReceive('deleteWebhook')->with(67890)->once();
    app()->instance(MailboxKitService::class, $mailboxKit);

    $slackCleanup = Mockery::mock(SlackAppCleanupService::class);
    $slackCleanup->shouldReceive('cleanup')->once();
    app()->instance(SlackAppCleanupService::class, $slackCleanup);

    $openRouter = Mockery::mock(OpenRouterProvisioningService::class);
    app()->instance(OpenRouterProvisioningService::class, $openRouter);

    DestroyTeamJob::dispatchSync($team);

    expect(Team::find($team->id))->toBeNull();
    expect(Agent::find($agent->id))->toBeNull();
});

it('revokes openrouter managed key when destroying team', function () {
    if (! class_exists('Provision\Billing\Models\ManagedOpenRouterKey')) {
        $this->markTestSkipped('Requires billing module');
    }

    $team = Team::factory()->subscribed()->create();
    $managedKey = ManagedOpenRouterKey::factory()->create([
        'team_id' => $team->id,
        'openrouter_key_hash' => 'test-hash-123',
    ]);

    if (class_exists(MailboxKitService::class)) {
        $mailboxKit = Mockery::mock(MailboxKitService::class);
        app()->instance(MailboxKitService::class, $mailboxKit);
    }

    $slackCleanup = Mockery::mock(SlackAppCleanupService::class);
    app()->instance(SlackAppCleanupService::class, $slackCleanup);

    $openRouter = Mockery::mock(OpenRouterProvisioningService::class);
    $openRouter->shouldReceive('deleteKey')->with('test-hash-123')->once();
    app()->instance(OpenRouterProvisioningService::class, $openRouter);

    DestroyTeamJob::dispatchSync($team);

    expect(Team::find($team->id))->toBeNull();
    expect(ManagedOpenRouterKey::find($managedKey->id))->toBeNull();
});

it('destroys cloud server and volume when destroying team', function () {
    $team = Team::factory()->subscribed()->create();
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'cloud_provider' => CloudProvider::DigitalOcean,
        'provider_server_id' => '999888',
        'provider_volume_id' => 'vol-abc',
    ]);

    if (class_exists(MailboxKitService::class)) {
        $mailboxKit = Mockery::mock(MailboxKitService::class);
        app()->instance(MailboxKitService::class, $mailboxKit);
    }

    $slackCleanup = Mockery::mock(SlackAppCleanupService::class);
    app()->instance(SlackAppCleanupService::class, $slackCleanup);

    $openRouter = Mockery::mock(OpenRouterProvisioningService::class);
    app()->instance(OpenRouterProvisioningService::class, $openRouter);

    $doService = Mockery::mock(DigitalOceanService::class);
    $doService->shouldReceive('deleteDroplet')->with('999888')->once();
    $doService->shouldReceive('deleteVolume')->with('vol-abc')->once();

    $cloudFactory = Mockery::mock(CloudServiceFactory::class);
    $cloudFactory->shouldReceive('makeFor')->andReturn($doService);
    app()->instance(CloudServiceFactory::class, $cloudFactory);

    DestroyTeamJob::dispatchSync($team);

    expect(Team::find($team->id))->toBeNull();
    expect(Server::find($server->id))->toBeNull();
});

it('continues cleanup even if external api calls fail', function () {
    if (! class_exists('Provision\Billing\Models\ManagedOpenRouterKey')) {
        $this->markTestSkipped('Requires billing module');
    }

    $team = Team::factory()->subscribed()->create();
    $agent = Agent::factory()->create(['team_id' => $team->id]);
    AgentEmailConnection::factory()->create([
        'agent_id' => $agent->id,
        'mailboxkit_inbox_id' => 11111,
    ]);
    ManagedOpenRouterKey::factory()->create([
        'team_id' => $team->id,
        'openrouter_key_hash' => 'fail-hash',
    ]);

    if (class_exists(MailboxKitService::class)) {
        $mailboxKit = Mockery::mock(MailboxKitService::class);
        $mailboxKit->shouldReceive('deleteInbox')->andThrow(new RuntimeException('API down'));
        $mailboxKit->shouldReceive('deleteWebhook')->never();
        app()->instance(MailboxKitService::class, $mailboxKit);
    }

    $slackCleanup = Mockery::mock(SlackAppCleanupService::class);
    $slackCleanup->shouldReceive('cleanup')->once();
    app()->instance(SlackAppCleanupService::class, $slackCleanup);

    $openRouter = Mockery::mock(OpenRouterProvisioningService::class);
    $openRouter->shouldReceive('deleteKey')->andThrow(new RuntimeException('API down'));
    app()->instance(OpenRouterProvisioningService::class, $openRouter);

    // Should not throw — failures are logged but don't block deletion
    DestroyTeamJob::dispatchSync($team);

    expect(Team::find($team->id))->toBeNull();
});
