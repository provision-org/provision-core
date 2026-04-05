<?php

use App\Jobs\RestartGatewayJob;
use App\Jobs\VerifyAgentChannelsJob;
use App\Models\Agent;
use App\Models\AgentTelegramConnection;
use App\Models\Server;
use App\Models\Team;
use App\Services\ChannelConfigBuilder;
use App\Services\SshService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

test('it passes verification when config matches database', function () {
    Bus::fake([RestartGatewayJob::class]);

    $team = Team::factory()->subscribed()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'harness_agent_id' => 'agent-verify',
    ]);
    AgentTelegramConnection::factory()->connected()->create([
        'agent_id' => $agent->id,
        'bot_token' => 'test-token-123',
    ]);

    // Server config matches what the builder would produce
    $correctConfig = json_encode([
        'agents' => ['list' => [['id' => 'agent-verify', 'name' => $agent->name]]],
        'channels' => [
            'telegram' => [
                'enabled' => true,
                'dmPolicy' => 'open',
                'allowFrom' => ['*'],
                'accounts' => [
                    'telegram-agent-verify' => [
                        'name' => 'telegram-agent-verify',
                        'botToken' => 'test-token-123',
                        'dmPolicy' => 'open',
                        'allowFrom' => ['*'],
                    ],
                ],
            ],
        ],
        'bindings' => [
            ['agentId' => 'agent-verify', 'match' => ['channel' => 'telegram', 'accountId' => 'telegram-agent-verify']],
        ],
        'plugins' => ['entries' => ['telegram' => ['enabled' => true]]],
    ]);

    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')->once();
    $sshService->shouldReceive('readFile')->with('/root/.openclaw/openclaw.json')->andReturn($correctConfig);
    $sshService->shouldReceive('disconnect')->once();
    // Should NOT write or restart since config is correct
    $sshService->shouldNotReceive('writeFile');

    (new VerifyAgentChannelsJob($agent))->handle($sshService, app(ChannelConfigBuilder::class));

    Bus::assertNotDispatched(RestartGatewayJob::class);

    // Should log a verified event
    expect($server->events()->where('event', 'agent_channels_verified')->exists())->toBeTrue();
});

test('it repairs config when binding is missing', function () {
    Bus::fake([RestartGatewayJob::class]);

    $team = Team::factory()->subscribed()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'harness_agent_id' => 'agent-broken',
    ]);
    AgentTelegramConnection::factory()->connected()->create([
        'agent_id' => $agent->id,
        'bot_token' => 'test-token-456',
    ]);

    // Server config is missing the binding
    $brokenConfig = json_encode([
        'agents' => ['list' => [['id' => 'agent-broken', 'name' => $agent->name]]],
        'channels' => [
            'telegram' => [
                'enabled' => true,
                'dmPolicy' => 'open',
                'allowFrom' => ['*'],
                'accounts' => [
                    'telegram-agent-broken' => [
                        'name' => 'telegram-agent-broken',
                        'botToken' => 'test-token-456',
                        'dmPolicy' => 'open',
                        'allowFrom' => ['*'],
                    ],
                ],
            ],
        ],
        'bindings' => [], // Missing!
        'plugins' => ['entries' => ['telegram' => ['enabled' => true]]],
    ]);

    $writtenConfig = null;

    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')->once();
    $sshService->shouldReceive('readFile')->with('/root/.openclaw/openclaw.json')->andReturn($brokenConfig);
    $sshService->shouldReceive('writeFile')->with('/root/.openclaw/openclaw.json', Mockery::on(function ($content) use (&$writtenConfig) {
        $writtenConfig = json_decode($content, true);

        return json_decode($content) !== null;
    }))->once();
    $sshService->shouldReceive('disconnect')->once();

    (new VerifyAgentChannelsJob($agent))->handle($sshService, app(ChannelConfigBuilder::class));

    // Should dispatch gateway restart
    Bus::assertDispatched(RestartGatewayJob::class);

    // Written config should have the binding
    $binding = collect($writtenConfig['bindings'])->firstWhere('agentId', 'agent-broken');
    expect($binding)->not->toBeNull();
    expect($binding['match'])->toBe(['channel' => 'telegram', 'accountId' => 'telegram-agent-broken']);

    // Should log a repaired event
    expect($server->events()->where('event', 'agent_channels_repaired')->exists())->toBeTrue();
});

test('it repairs config when dmPolicy is pairing instead of open', function () {
    Bus::fake([RestartGatewayJob::class]);

    $team = Team::factory()->subscribed()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'harness_agent_id' => 'agent-pairing',
    ]);
    AgentTelegramConnection::factory()->connected()->create([
        'agent_id' => $agent->id,
        'bot_token' => 'test-token-789',
    ]);

    // dmPolicy was reset by openclaw doctor --fix
    $brokenConfig = json_encode([
        'agents' => ['list' => [['id' => 'agent-pairing', 'name' => $agent->name]]],
        'channels' => [
            'telegram' => [
                'enabled' => true,
                'dmPolicy' => 'open',
                'allowFrom' => ['*'],
                'accounts' => [
                    'telegram-agent-pairing' => [
                        'name' => 'telegram-agent-pairing',
                        'botToken' => 'test-token-789',
                        'dmPolicy' => 'pairing', // Doctor set this!
                        'allowFrom' => ['*'],
                    ],
                ],
            ],
        ],
        'bindings' => [
            ['agentId' => 'agent-pairing', 'match' => ['channel' => 'telegram', 'accountId' => 'telegram-agent-pairing']],
        ],
        'plugins' => ['entries' => ['telegram' => ['enabled' => true]]],
    ]);

    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')->once();
    $sshService->shouldReceive('readFile')->with('/root/.openclaw/openclaw.json')->andReturn($brokenConfig);
    $sshService->shouldReceive('writeFile')->once();
    $sshService->shouldReceive('disconnect')->once();

    (new VerifyAgentChannelsJob($agent))->handle($sshService, app(ChannelConfigBuilder::class));

    Bus::assertDispatched(RestartGatewayJob::class);
});

test('it skips inactive agents', function () {
    $team = Team::factory()->subscribed()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->pending()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
    ]);

    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldNotReceive('connect');

    (new VerifyAgentChannelsJob($agent))->handle($sshService, app(ChannelConfigBuilder::class));
});

test('it skips agents without channels', function () {
    $team = Team::factory()->subscribed()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
    ]);

    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldNotReceive('connect');

    (new VerifyAgentChannelsJob($agent))->handle($sshService, app(ChannelConfigBuilder::class));
});
