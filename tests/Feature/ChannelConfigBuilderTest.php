<?php

use App\Models\Agent;
use App\Models\AgentSlackConnection;
use App\Models\AgentTelegramConnection;
use App\Models\Server;
use App\Models\Team;
use App\Services\ChannelConfigBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('single agent gets named account id', function () {
    $team = Team::factory()->subscribed()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'harness_agent_id' => 'agent-1',
    ]);
    AgentTelegramConnection::factory()->connected()->create(['agent_id' => $agent->id]);

    $builder = app(ChannelConfigBuilder::class);
    $accounts = $builder->collectAccounts($server);

    expect($accounts['telegram'])->toHaveCount(1);
    expect($builder->resolveAccountId('telegram', 'agent-1', $accounts))->toBe('telegram-agent-1');
});

test('multi agent gets named account ids', function () {
    $team = Team::factory()->subscribed()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);

    $agent1 = Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'harness_agent_id' => 'agent-1',
    ]);
    AgentTelegramConnection::factory()->connected()->create(['agent_id' => $agent1->id]);

    $agent2 = Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'harness_agent_id' => 'agent-2',
    ]);
    AgentTelegramConnection::factory()->connected()->create(['agent_id' => $agent2->id]);

    $builder = app(ChannelConfigBuilder::class);
    $accounts = $builder->collectAccounts($server);

    expect($accounts['telegram'])->toHaveCount(2);
    expect($builder->resolveAccountId('telegram', 'agent-1', $accounts))->toBe('telegram-agent-1');
    expect($builder->resolveAccountId('telegram', 'agent-2', $accounts))->toBe('telegram-agent-2');
});

test('buildConfig produces correct structure for single agent', function () {
    $team = Team::factory()->subscribed()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'harness_agent_id' => 'agent-1',
    ]);
    AgentTelegramConnection::factory()->connected()->create([
        'agent_id' => $agent->id,
        'bot_token' => 'test-bot-token',
    ]);

    $builder = app(ChannelConfigBuilder::class);
    $accounts = $builder->collectAccounts($server);
    $config = $builder->buildConfig($accounts);

    // Channel config
    expect($config['channels']['telegram']['enabled'])->toBeTrue();
    expect($config['channels']['telegram']['dmPolicy'])->toBe('open');
    expect($config['channels']['telegram']['accounts']['telegram-agent-1'])->toMatchArray([
        'name' => 'telegram-agent-1',
        'botToken' => 'test-bot-token',
        'dmPolicy' => 'open',
        'allowFrom' => ['*'],
    ]);

    // Telegram should NOT have Slack-specific streaming keys
    expect($config['channels']['telegram'])->not->toHaveKey('nativeStreaming');

    // Binding
    expect($config['bindings'])->toHaveCount(1);
    expect($config['bindings'][0])->toBe([
        'agentId' => 'agent-1',
        'match' => ['channel' => 'telegram', 'accountId' => 'telegram-agent-1'],
    ]);

    // Plugin
    expect($config['plugins']['telegram'])->toBe(['enabled' => true]);
});

test('buildConfig handles multi-channel multi-agent', function () {
    $team = Team::factory()->subscribed()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);

    $agent1 = Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'harness_agent_id' => 'agent-1',
    ]);
    AgentSlackConnection::factory()->connected()->create(['agent_id' => $agent1->id]);
    AgentTelegramConnection::factory()->connected()->create(['agent_id' => $agent1->id]);

    $agent2 = Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'harness_agent_id' => 'agent-2',
    ]);
    AgentTelegramConnection::factory()->connected()->create(['agent_id' => $agent2->id]);

    $builder = app(ChannelConfigBuilder::class);
    $accounts = $builder->collectAccounts($server);
    $config = $builder->buildConfig($accounts);

    // Slack: 1 agent — uses named account with streaming config
    expect($config['channels']['slack']['accounts'])->toHaveKey('slack-agent-1');
    expect($config['channels']['slack']['nativeStreaming'])->toBeTrue();
    expect($config['channels']['slack']['streaming'])->toBe('partial');
    expect($config['channels']['slack']['accounts']['slack-agent-1']['nativeStreaming'])->toBeTrue();

    // Telegram: 2 agents — uses named accounts
    expect($config['channels']['telegram']['accounts'])->toHaveKey('telegram-agent-1');
    expect($config['channels']['telegram']['accounts'])->toHaveKey('telegram-agent-2');

    // Should have 3 bindings total
    expect($config['bindings'])->toHaveCount(3);
});

test('applyToConfig clears old channels and rebuilds', function () {
    $team = Team::factory()->subscribed()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'harness_agent_id' => 'agent-1',
    ]);
    AgentTelegramConnection::factory()->connected()->create(['agent_id' => $agent->id]);

    $config = [
        'channels' => [
            'slack' => ['enabled' => true, 'accounts' => ['stale' => []]],
        ],
        'plugins' => ['entries' => ['slack' => ['enabled' => true]]],
        'bindings' => [['agentId' => 'old', 'match' => ['channel' => 'slack']]],
        'agents' => ['list' => []],
    ];

    $builder = app(ChannelConfigBuilder::class);
    $builder->applyToConfig($config, $server);

    // Old slack should be removed
    expect($config['channels'])->not->toHaveKey('slack');
    expect($config['plugins']['entries'])->not->toHaveKey('slack');

    // New telegram should be added
    expect($config['channels'])->toHaveKey('telegram');
    expect($config['plugins']['entries'])->toHaveKey('telegram');
    expect($config['bindings'])->toHaveCount(1);
    expect($config['bindings'][0]['match']['channel'])->toBe('telegram');
});

test('resolveReplyChannel returns correct channel and account', function () {
    $team = Team::factory()->subscribed()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'harness_agent_id' => 'agent-1',
    ]);
    AgentTelegramConnection::factory()->connected()->create(['agent_id' => $agent->id]);

    $builder = app(ChannelConfigBuilder::class);
    $result = $builder->resolveReplyChannel($agent);

    expect($result)->toBe(['channel' => 'telegram', 'account' => 'telegram-agent-1']);
});

test('resolveReplyChannel returns null without channels', function () {
    $team = Team::factory()->subscribed()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
    ]);

    $builder = app(ChannelConfigBuilder::class);
    $result = $builder->resolveReplyChannel($agent);

    expect($result)->toBeNull();
});

test('slack requires both bot_token and app_token', function () {
    $team = Team::factory()->subscribed()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'harness_agent_id' => 'agent-1',
    ]);

    // Create slack connection with bot_token but no app_token
    AgentSlackConnection::factory()->create([
        'agent_id' => $agent->id,
        'bot_token' => 'xoxb-test',
        'app_token' => null,
    ]);

    $builder = app(ChannelConfigBuilder::class);
    $accounts = $builder->collectAccounts($server);

    expect($accounts['slack'])->toBeEmpty();
});
