<?php

use App\Contracts\CommandExecutor;
use App\Enums\ChatMessageRole;
use App\Enums\CloudProvider;
use App\Enums\HarnessType;
use App\Jobs\EnsureProvisionDaemonCurrentJob;
use App\Models\Agent;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\OpenClawSessionDiscovery;
use App\Models\Server;
use App\Models\User;
use App\Services\HarnessManager;
use Illuminate\Support\Facades\Cache;

/**
 * @param  array<string, mixed>  $state
 */
function dockerServerForDaemonUpdate(array $state = []): Server
{
    $user = User::factory()->withPersonalTeam()->create();
    $server = Server::factory()->running()->create([
        'team_id' => $user->currentTeam->id,
        'cloud_provider' => CloudProvider::Docker,
    ]);
    $server->forceFill(array_merge([
        'daemon_version' => config('provision.provisiond_version'),
        'daemon_capabilities' => ['chat-relay-v1'],
        'daemon_active_runs' => [],
    ], $state))->saveQuietly();

    return $server->fresh();
}

test('daemon maintenance backfills Docker Gateway auth without replacing unrelated config', function () {
    $server = dockerServerForDaemonUpdate(['gateway_token' => null]);
    $remoteConfig = json_encode([
        'gateway' => [
            'mode' => 'local',
            'bind' => 'loopback',
            'auth' => ['existingOption' => true],
        ],
        'channels' => (object) [],
        'messages' => ['queue' => ['mode' => 'collect']],
    ], JSON_THROW_ON_ERROR);
    $writtenConfig = null;

    $executor = Mockery::mock(CommandExecutor::class);
    $executor->shouldReceive('readFile')->once()->with('/root/.openclaw/openclaw.json')->andReturn($remoteConfig);
    $executor->shouldReceive('writeFile')
        ->once()
        ->with('/root/.openclaw/openclaw.json.provision-new', Mockery::on(function (string $contents) use (&$writtenConfig): bool {
            $writtenConfig = $contents;

            return true;
        }));
    $executor->shouldReceive('exec')
        ->once()
        ->with(Mockery::on(fn (string $command) => str_contains($command, 'chmod 0600')
            && str_contains($command, 'openclaw.json.provision-new')))
        ->andReturn('');
    $executor->shouldReceive('exec')
        ->once()
        ->with(Mockery::on(fn (string $command) => str_contains($command, "pkill -TERM -f '[o]penclaw gateway'")))
        ->andReturn('');

    $manager = Mockery::mock(HarnessManager::class);
    $manager->shouldReceive('resolveExecutor')->once()->withArgs(fn (Server $value) => $value->is($server))->andReturn($executor);

    (new EnsureProvisionDaemonCurrentJob($server))->handle($manager);

    $decoded = json_decode($writtenConfig, true, flags: JSON_THROW_ON_ERROR);
    $gatewayToken = $server->fresh()->gateway_token;
    expect($gatewayToken)->toBeString()->not->toBeEmpty()
        ->and($decoded['gateway']['mode'])->toBe('local')
        ->and($decoded['gateway']['bind'])->toBe('loopback')
        ->and($decoded['gateway']['auth'])->toBe([
            'existingOption' => true,
            'mode' => 'token',
            'token' => $gatewayToken,
        ])
        ->and($decoded['messages'])->toBe(['queue' => ['mode' => 'collect']])
        ->and($writtenConfig)->toContain('"channels": {}');
});

test('daemon maintenance adopts an existing Docker Gateway token without restarting it', function () {
    $server = dockerServerForDaemonUpdate(['gateway_token' => null]);
    $remoteConfig = json_encode([
        'gateway' => [
            'auth' => [
                'mode' => 'token',
                'token' => 'existing-remote-token',
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $executor = Mockery::mock(CommandExecutor::class);
    $executor->shouldReceive('readFile')->once()->andReturn($remoteConfig);
    $executor->shouldNotReceive('writeFile');
    $executor->shouldNotReceive('exec');

    $manager = Mockery::mock(HarnessManager::class);
    $manager->shouldReceive('resolveExecutor')->once()->andReturn($executor);

    (new EnsureProvisionDaemonCurrentJob($server))->handle($manager);

    expect($server->fresh()->gateway_token)->toBe('existing-remote-token');
});

test('daemon maintenance holds an execution lock for the full update', function () {
    $server = dockerServerForDaemonUpdate(['gateway_token' => 'locked-token']);
    $lock = Cache::lock("provisiond-update-execution:{$server->id}", 180);
    expect($lock->get())->toBeTrue();

    try {
        $manager = Mockery::mock(HarnessManager::class);
        $manager->shouldNotReceive('resolveExecutor');

        (new EnsureProvisionDaemonCurrentJob($server))->handle($manager);
    } finally {
        $lock->release();
    }
});

test('current Docker daemon defers Gateway auth maintenance while heartbeat reports active runs', function () {
    $server = dockerServerForDaemonUpdate([
        'gateway_token' => 'active-daemon-run-token',
        'last_health_check' => now(),
        'daemon_active_runs' => ['active-daemon-run'],
    ]);

    $manager = Mockery::mock(HarnessManager::class);
    $manager->shouldNotReceive('resolveExecutor');

    (new EnsureProvisionDaemonCurrentJob($server))->handle($manager);
});

test('Docker Gateway maintenance waits for an active dashboard chat', function () {
    $server = dockerServerForDaemonUpdate(['gateway_token' => 'active-chat-token']);
    $agent = Agent::factory()->create([
        'team_id' => $server->team_id,
        'server_id' => $server->id,
        'harness_type' => HarnessType::OpenClaw,
        'harness_agent_id' => 'active-chat-agent',
    ]);
    $conversation = ChatConversation::factory()->create([
        'agent_id' => $agent->id,
        'user_id' => $server->team->user_id,
    ]);
    ChatMessage::factory()->create([
        'chat_conversation_id' => $conversation->id,
        'role' => ChatMessageRole::User,
        'delivery_status' => 'running',
        'upstream_run_id' => 'active-dashboard-run',
        'sent_at' => now(),
    ]);

    $manager = Mockery::mock(HarnessManager::class);
    $manager->shouldNotReceive('resolveExecutor');

    (new EnsureProvisionDaemonCurrentJob($server))->handle($manager);
});

test('Docker Gateway maintenance waits for an active server-discovered chat', function () {
    $server = dockerServerForDaemonUpdate(['gateway_token' => 'active-server-chat-token']);
    $agent = Agent::factory()->create([
        'team_id' => $server->team_id,
        'server_id' => $server->id,
        'harness_type' => HarnessType::OpenClaw,
        'harness_agent_id' => 'active-server-chat-agent',
    ]);
    OpenClawSessionDiscovery::query()->create([
        'server_id' => $server->id,
        'agent_id' => $agent->id,
        'session_key' => 'agent:active-server-chat-agent:webchat:external',
        'kind' => 'direct',
        'channel' => 'webchat',
        'title' => 'Active server chat',
        'has_active_run' => true,
        'active_run_ids' => ['active-server-run'],
        'upstream_updated_at' => now(),
        'discovered_at' => now(),
    ]);

    $manager = Mockery::mock(HarnessManager::class);
    $manager->shouldNotReceive('resolveExecutor');

    (new EnsureProvisionDaemonCurrentJob($server))->handle($manager);
});

test('Docker daemon replacement waits for the old process before starting the new one', function () {
    $server = dockerServerForDaemonUpdate([
        'gateway_token' => 'current-gateway-token',
        'daemon_version' => '0.3.0',
    ]);
    $remoteConfig = json_encode([
        'gateway' => [
            'auth' => [
                'mode' => 'token',
                'token' => 'current-gateway-token',
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $executor = Mockery::mock(CommandExecutor::class);
    $executor->shouldReceive('readFile')->once()->andReturn($remoteConfig);
    $executor->shouldReceive('writeFile')->twice();
    $executor->shouldReceive('exec')->once()->with(Mockery::on(fn (string $command) => str_starts_with($command, 'chmod 0755')))->andReturn('');
    $executor->shouldReceive('exec')->once()->with(Mockery::on(fn (string $command) => str_starts_with($command, 'node --check')))->andReturn('');
    $executor->shouldReceive('exec')->once()->with(Mockery::on(fn (string $command) => str_contains($command, 'mv ')))->andReturn('');
    $executor->shouldReceive('exec')
        ->once()
        ->with(Mockery::on(fn (string $command) => str_contains($command, 'for attempt in {1..30}')
            && str_contains($command, "pgrep -f '^node /opt/provisiond/provisiond[.]mjs( |$)'")
            && strpos($command, 'for attempt') < strpos($command, 'nohup node')))
        ->andReturn('');

    $manager = Mockery::mock(HarnessManager::class);
    $manager->shouldReceive('resolveExecutor')->once()->andReturn($executor);

    (new EnsureProvisionDaemonCurrentJob($server))->handle($manager);

    expect($server->fresh()->daemon_version)->toBeNull()
        ->and($server->fresh()->daemon_capabilities)->toBeNull();
});
