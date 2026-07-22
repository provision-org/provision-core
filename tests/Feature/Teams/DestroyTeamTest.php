<?php

use App\Enums\AgentStatus;
use App\Enums\CloudProvider;
use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Jobs\DestroyTeamJob;
use App\Models\Agent;
use App\Models\AgentApiToken;
use App\Models\AgentEmailConnection;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\AwsService;
use App\Services\CloudServiceFactory;
use App\Services\DigitalOceanService;
use App\Services\LinodeService;
use App\Services\PublishArtifactService;
use App\Services\SlackAppCleanupService;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Provision\Billing\Models\ManagedOpenRouterKey;
use Provision\Billing\Services\OpenRouterProvisioningService;
use Provision\MailboxKit\Services\MailboxKitService;

uses(RefreshDatabase::class);

afterEach(function () {
    Sleep::fake(false);
});

it('dispatches DestroyTeamJob when team owner deletes team', function () {
    Queue::fake();

    $user = User::factory()->withPersonalTeam()->create();
    $team = Team::factory()->subscribed()->create(['user_id' => $user->id]);
    $team->members()->attach($user, ['role' => TeamRole::Admin->value]);
    $user->switchTeam($team);
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
    ]);
    $apiToken = AgentApiToken::createForAgent($agent)['token'];

    $this->actingAs($user)
        ->delete(route('teams.destroy', $team))
        ->assertRedirect();

    Queue::assertPushed(DestroyTeamJob::class, fn ($job) => $job->team->id === $team->id);
    expect($server->fresh()->status)->toBe(ServerStatus::Destroying)
        ->and($agent->fresh()->status)->toBe(AgentStatus::Paused)
        ->and(AgentApiToken::find($apiToken->id))->toBeNull();
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

it('retries DO volume conflicts for the full detach backoff window', function () {
    Sleep::fake();

    $team = Team::factory()->subscribed()->create();
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'cloud_provider' => CloudProvider::DigitalOcean,
        'provider_server_id' => '999888',
        'provider_volume_id' => 'vol-detaching',
    ]);

    if (class_exists(MailboxKitService::class)) {
        app()->instance(MailboxKitService::class, Mockery::mock(MailboxKitService::class));
    }
    app()->instance(SlackAppCleanupService::class, Mockery::mock(SlackAppCleanupService::class));
    app()->instance(OpenRouterProvisioningService::class, Mockery::mock(OpenRouterProvisioningService::class));

    $conflict = new RequestException(new Response(new Psr7Response(409, [], json_encode([
        'id' => 'conflict',
        'message' => 'attached volume cannot be deleted',
    ], JSON_THROW_ON_ERROR))));
    $deleteAttempts = 0;

    $doService = Mockery::mock(DigitalOceanService::class);
    $doService->shouldReceive('deleteDroplet')->with('999888')->once();
    $doService->shouldReceive('deleteVolume')
        ->with('vol-detaching')
        ->times(5)
        ->andReturnUsing(function () use (&$deleteAttempts, $conflict): void {
            $deleteAttempts++;

            if ($deleteAttempts < 5) {
                throw $conflict;
            }
        });

    $cloudFactory = Mockery::mock(CloudServiceFactory::class);
    $cloudFactory->shouldReceive('makeFor')->andReturn($doService);
    app()->instance(CloudServiceFactory::class, $cloudFactory);

    DestroyTeamJob::dispatchSync($team);

    Sleep::assertSequence([
        Sleep::for(2)->seconds(),
        Sleep::for(4)->seconds(),
        Sleep::for(8)->seconds(),
        Sleep::for(16)->seconds(),
    ]);
    expect(Team::find($team->id))->toBeNull();
    expect(Server::find($server->id))->toBeNull();
});

it('retains teardown state after DO volume retries exhaust and can safely retry', function () {
    Sleep::fake();

    $team = Team::factory()->subscribed()->create();
    $agent = Agent::factory()->create(['team_id' => $team->id]);
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'cloud_provider' => CloudProvider::DigitalOcean,
        'provider_server_id' => '999888',
        'provider_volume_id' => 'vol-still-attached',
        'provider_firewall_id' => 'fw-cleanup',
    ]);

    if (class_exists(MailboxKitService::class)) {
        app()->instance(MailboxKitService::class, Mockery::mock(MailboxKitService::class));
    }
    app()->instance(SlackAppCleanupService::class, Mockery::mock(SlackAppCleanupService::class));
    app()->instance(OpenRouterProvisioningService::class, Mockery::mock(OpenRouterProvisioningService::class));

    $teardownEvents = [];
    $artifacts = Mockery::mock(PublishArtifactService::class);
    $artifacts->shouldReceive('teardownAgent')
        ->withArgs(fn (Agent $candidate, bool $requireServerCleanup) => $candidate->is($agent) && ! $requireServerCleanup)
        ->twice()
        ->andReturnUsing(function () use (&$teardownEvents): void {
            $teardownEvents[] = 'artifacts';
        });
    app()->instance(PublishArtifactService::class, $artifacts);

    $conflict = new RequestException(new Response(new Psr7Response(409)));
    $notFound = new RequestException(new Response(new Psr7Response(404)));
    $dropletDeleteAttempts = 0;
    $volumeDeleteAttempts = 0;

    $doService = Mockery::mock(DigitalOceanService::class);
    $doService->shouldReceive('deleteDroplet')
        ->with('999888')
        ->twice()
        ->andReturnUsing(function () use (&$dropletDeleteAttempts, &$teardownEvents, $notFound): void {
            $teardownEvents[] = 'droplet';
            $dropletDeleteAttempts++;

            if ($dropletDeleteAttempts === 2) {
                throw $notFound;
            }
        });
    $doService->shouldReceive('deleteVolume')
        ->with('vol-still-attached')
        ->times(6)
        ->andReturnUsing(function () use (&$volumeDeleteAttempts, $conflict): void {
            $volumeDeleteAttempts++;

            if ($volumeDeleteAttempts <= 5) {
                throw $conflict;
            }
        });
    $doService->shouldReceive('deleteFirewall')->with('fw-cleanup')->twice();

    $cloudFactory = Mockery::mock(CloudServiceFactory::class);
    $cloudFactory->shouldReceive('makeFor')->twice()->andReturn($doService);
    app()->instance(CloudServiceFactory::class, $cloudFactory);

    expect(fn () => DestroyTeamJob::dispatchSync($team))
        ->toThrow(RuntimeException::class, 'team retained for retry');

    Sleep::assertSequence([
        Sleep::for(2)->seconds(),
        Sleep::for(4)->seconds(),
        Sleep::for(8)->seconds(),
        Sleep::for(16)->seconds(),
    ]);
    expect(Team::find($team->id))->not->toBeNull();
    expect(Server::find($server->id))->not->toBeNull();

    Sleep::fake();

    DestroyTeamJob::dispatchSync($team->fresh());

    Sleep::assertNeverSlept();
    expect(Team::find($team->id))->toBeNull();
    expect(Server::find($server->id))->toBeNull();
    expect($teardownEvents)->toBe([
        'artifacts',
        'droplet',
        'artifacts',
        'droplet',
    ]);
});

it('retains the team and skips cloud teardown when artifact cleanup fails', function () {
    $team = Team::factory()->subscribed()->create();
    $agent = Agent::factory()->create(['team_id' => $team->id]);
    $otherAgent = Agent::factory()->create(['team_id' => $team->id]);
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'cloud_provider' => CloudProvider::DigitalOcean,
        'provider_server_id' => '999888',
    ]);

    if (class_exists(MailboxKitService::class)) {
        app()->instance(MailboxKitService::class, Mockery::mock(MailboxKitService::class));
    }
    app()->instance(SlackAppCleanupService::class, Mockery::mock(SlackAppCleanupService::class));
    app()->instance(OpenRouterProvisioningService::class, Mockery::mock(OpenRouterProvisioningService::class));

    $artifacts = Mockery::mock(PublishArtifactService::class);
    $artifactCleanupAttempts = [];
    $artifacts->shouldReceive('teardownAgent')->twice()
        ->withArgs(fn (Agent $candidate, bool $requireServerCleanup) => ! $requireServerCleanup)
        ->andReturnUsing(function (Agent $candidate) use ($agent, &$artifactCleanupAttempts): void {
            $artifactCleanupAttempts[] = $candidate->id;

            if ($candidate->is($agent)) {
                throw new RuntimeException('artifact host unavailable');
            }
        });
    app()->instance(PublishArtifactService::class, $artifacts);

    $doService = Mockery::mock(DigitalOceanService::class);
    $doService->shouldReceive('deleteDroplet')->never();

    $cloudFactory = Mockery::mock(CloudServiceFactory::class);
    $cloudFactory->shouldReceive('makeFor')->never();
    app()->instance(CloudServiceFactory::class, $cloudFactory);

    expect(fn () => DestroyTeamJob::dispatchSync($team))
        ->toThrow(RuntimeException::class, 'Artifact DNS cleanup failed; team retained for retry.');

    expect(Team::find($team->id))->not->toBeNull();
    expect(Agent::find($agent->id))->not->toBeNull();
    expect(Agent::find($otherAgent->id))->not->toBeNull();
    expect(Server::find($server->id))->not->toBeNull()
        ->and(Server::find($server->id)->provider_server_id)->toBe('999888');
    expect($artifactCleanupAttempts)->toContain($agent->id, $otherAgent->id);
});

it('releases the DO firewall when destroying a team that has one (issue #37)', function () {
    $team = Team::factory()->subscribed()->create();
    Server::factory()->create([
        'team_id' => $team->id,
        'cloud_provider' => CloudProvider::DigitalOcean,
        'provider_server_id' => '999888',
        'provider_volume_id' => 'vol-abc',
        'provider_firewall_id' => 'fw-leak-37',
    ]);

    if (class_exists(MailboxKitService::class)) {
        app()->instance(MailboxKitService::class, Mockery::mock(MailboxKitService::class));
    }
    app()->instance(SlackAppCleanupService::class, Mockery::mock(SlackAppCleanupService::class));
    app()->instance(OpenRouterProvisioningService::class, Mockery::mock(OpenRouterProvisioningService::class));

    $doService = Mockery::mock(DigitalOceanService::class);
    $doService->shouldReceive('deleteDroplet')->with('999888')->once();
    $doService->shouldReceive('deleteVolume')->with('vol-abc')->once();
    $doService->shouldReceive('deleteFirewall')->with('fw-leak-37')->once();

    $cloudFactory = Mockery::mock(CloudServiceFactory::class);
    $cloudFactory->shouldReceive('makeFor')->andReturn($doService);
    app()->instance(CloudServiceFactory::class, $cloudFactory);

    DestroyTeamJob::dispatchSync($team);
});

it('waits for AWS instance termination before releasing its security group', function () {
    $team = Team::factory()->subscribed()->create();
    Server::factory()->create([
        'team_id' => $team->id,
        'cloud_provider' => CloudProvider::Aws,
        'provider_server_id' => 'i-0abc123',
        'provider_firewall_id' => 'sg-0abc123',
    ]);

    if (class_exists(MailboxKitService::class)) {
        app()->instance(MailboxKitService::class, Mockery::mock(MailboxKitService::class));
    }
    app()->instance(SlackAppCleanupService::class, Mockery::mock(SlackAppCleanupService::class));
    app()->instance(OpenRouterProvisioningService::class, Mockery::mock(OpenRouterProvisioningService::class));

    // The security group can only be deleted once the instance is fully
    // terminated, so the wait MUST happen before deleteSecurityGroup.
    $aws = Mockery::mock(AwsService::class);
    $aws->shouldReceive('terminateInstance')->with('i-0abc123')->once()->ordered();
    $aws->shouldReceive('waitForInstanceTerminated')->with('i-0abc123')->once()->ordered();
    $aws->shouldReceive('deleteSecurityGroup')->with('sg-0abc123')->once()->ordered();

    $cloudFactory = Mockery::mock(CloudServiceFactory::class);
    $cloudFactory->shouldReceive('makeFor')->andReturn($aws);
    app()->instance(CloudServiceFactory::class, $cloudFactory);

    DestroyTeamJob::dispatchSync($team);
});

it('releases the Linode Cloud Firewall when destroying a team that has one', function () {
    $team = Team::factory()->subscribed()->create();
    Server::factory()->create([
        'team_id' => $team->id,
        'cloud_provider' => CloudProvider::Linode,
        'provider_server_id' => '55501',
        'provider_volume_id' => '9001',
        'provider_firewall_id' => '4242',
    ]);

    if (class_exists(MailboxKitService::class)) {
        app()->instance(MailboxKitService::class, Mockery::mock(MailboxKitService::class));
    }
    app()->instance(SlackAppCleanupService::class, Mockery::mock(SlackAppCleanupService::class));
    app()->instance(OpenRouterProvisioningService::class, Mockery::mock(OpenRouterProvisioningService::class));

    $linode = Mockery::mock(LinodeService::class);
    $linode->shouldReceive('deleteInstance')->with('55501')->once();
    $linode->shouldReceive('detachVolume')->with('9001')->once();
    $linode->shouldReceive('deleteVolume')->with('9001')->once();
    $linode->shouldReceive('deleteFirewall')->with(4242)->once();

    $cloudFactory = Mockery::mock(CloudServiceFactory::class);
    $cloudFactory->shouldReceive('makeFor')->andReturn($linode);
    app()->instance(CloudServiceFactory::class, $cloudFactory);

    DestroyTeamJob::dispatchSync($team);
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
