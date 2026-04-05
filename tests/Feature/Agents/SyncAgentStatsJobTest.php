<?php

use App\Enums\AgentStatus;
use App\Enums\ServerStatus;
use App\Jobs\SyncAgentStatsJob;
use App\Models\Agent;
use App\Models\AgentDailyStat;
use App\Models\Server;
use App\Models\Team;
use App\Services\SshService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('it syncs stats for active agents on running servers', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'status' => AgentStatus::Active,
        'harness_agent_id' => 'agent-001',
    ]);

    $statsOutput = json_encode([
        'totalSessions' => 12,
        'totalMessages' => 48,
        'tokensInput' => 150000,
        'tokensOutput' => 30000,
        'lastActiveAt' => '2026-03-03T10:00:00.000Z',
    ]);

    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')->once()->andReturnSelf();
    $sshService->shouldReceive('writeFile')->once()->with('/tmp/agent-stats.js', Mockery::type('string'));
    $sshService->shouldReceive('exec')->once()->with('node /tmp/agent-stats.js agent-001')->andReturn($statsOutput);
    $sshService->shouldReceive('disconnect')->once();

    (new SyncAgentStatsJob)->handle($sshService);

    $agent->refresh();
    expect($agent->stats_total_sessions)->toBe(12)
        ->and($agent->stats_total_messages)->toBe(48)
        ->and($agent->stats_tokens_input)->toBe(150000)
        ->and($agent->stats_tokens_output)->toBe(30000)
        ->and($agent->stats_last_active_at)->not->toBeNull()
        ->and($agent->stats_synced_at)->not->toBeNull();
});

test('it skips servers that are not running', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'status' => ServerStatus::Stopped,
    ]);
    Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'status' => AgentStatus::Active,
        'harness_agent_id' => 'agent-002',
    ]);

    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldNotReceive('connect');

    (new SyncAgentStatsJob)->handle($sshService);
});

test('it skips agents that are not active', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'status' => AgentStatus::Pending,
        'harness_agent_id' => 'agent-003',
    ]);

    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldNotReceive('connect');

    (new SyncAgentStatsJob)->handle($sshService);
});

test('one agent failure does not block others', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent1 = Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'status' => AgentStatus::Active,
        'harness_agent_id' => 'agent-fail',
    ]);
    $agent2 = Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'status' => AgentStatus::Active,
        'harness_agent_id' => 'agent-ok',
    ]);

    $statsOutput = json_encode([
        'totalSessions' => 5,
        'totalMessages' => 20,
        'tokensInput' => 1000,
        'tokensOutput' => 500,
        'lastActiveAt' => null,
    ]);

    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')->once()->andReturnSelf();
    $sshService->shouldReceive('writeFile')->once();
    $sshService->shouldReceive('exec')->once()
        ->with('node /tmp/agent-stats.js agent-fail')
        ->andThrow(new RuntimeException('Script failed'));
    $sshService->shouldReceive('exec')->once()
        ->with('node /tmp/agent-stats.js agent-ok')
        ->andReturn($statsOutput);
    $sshService->shouldReceive('disconnect')->once();

    (new SyncAgentStatsJob)->handle($sshService);

    expect($agent1->fresh()->stats_synced_at)->toBeNull();
    expect($agent2->fresh()->stats_total_sessions)->toBe(5);
    expect($agent2->fresh()->stats_synced_at)->not->toBeNull();
});

test('it handles invalid json output gracefully', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'status' => AgentStatus::Active,
        'harness_agent_id' => 'agent-bad',
    ]);

    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')->once()->andReturnSelf();
    $sshService->shouldReceive('writeFile')->once();
    $sshService->shouldReceive('exec')->once()->andReturn('not valid json');
    $sshService->shouldReceive('disconnect')->once();

    (new SyncAgentStatsJob)->handle($sshService);

    expect($agent->fresh()->stats_synced_at)->toBeNull();
});

test('it disconnects even when server connection fails', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'status' => AgentStatus::Active,
        'harness_agent_id' => 'agent-dc',
    ]);

    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')->once()->andThrow(new RuntimeException('Connection refused'));
    $sshService->shouldReceive('disconnect')->once();

    (new SyncAgentStatsJob)->handle($sshService);
});

test('it creates daily stats snapshot during sync', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'status' => AgentStatus::Active,
        'harness_agent_id' => 'agent-daily',
    ]);

    $statsOutput = json_encode([
        'totalSessions' => 5,
        'totalMessages' => 20,
        'tokensInput' => 10000,
        'tokensOutput' => 2000,
        'lastActiveAt' => null,
    ]);

    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')->once()->andReturnSelf();
    $sshService->shouldReceive('writeFile')->once();
    $sshService->shouldReceive('exec')->once()->andReturn($statsOutput);
    $sshService->shouldReceive('disconnect')->once();

    (new SyncAgentStatsJob)->handle($sshService);

    $dailyStat = AgentDailyStat::query()
        ->where('agent_id', $agent->id)
        ->where('date', now()->toDateString())
        ->first();

    expect($dailyStat)->not->toBeNull()
        ->and($dailyStat->cumulative_tokens_input)->toBe(10000)
        ->and($dailyStat->cumulative_tokens_output)->toBe(2000)
        ->and($dailyStat->cumulative_messages)->toBe(20)
        ->and($dailyStat->cumulative_sessions)->toBe(5);
});

test('it updates existing daily stats on subsequent syncs', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'status' => AgentStatus::Active,
        'harness_agent_id' => 'agent-update',
    ]);

    // Pre-existing daily stat for today
    AgentDailyStat::query()->create([
        'agent_id' => $agent->id,
        'date' => now()->toDateString(),
        'cumulative_tokens_input' => 5000,
        'cumulative_tokens_output' => 1000,
        'cumulative_messages' => 10,
        'cumulative_sessions' => 3,
    ]);

    $statsOutput = json_encode([
        'totalSessions' => 7,
        'totalMessages' => 25,
        'tokensInput' => 15000,
        'tokensOutput' => 3000,
        'lastActiveAt' => null,
    ]);

    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')->once()->andReturnSelf();
    $sshService->shouldReceive('writeFile')->once();
    $sshService->shouldReceive('exec')->once()->andReturn($statsOutput);
    $sshService->shouldReceive('disconnect')->once();

    (new SyncAgentStatsJob)->handle($sshService);

    $dailyStats = AgentDailyStat::query()
        ->where('agent_id', $agent->id)
        ->where('date', now()->toDateString())
        ->get();

    expect($dailyStats)->toHaveCount(1);
    expect($dailyStats->first()->cumulative_tokens_input)->toBe(15000);
    expect($dailyStats->first()->cumulative_tokens_output)->toBe(3000);
    expect($dailyStats->first()->cumulative_messages)->toBe(25);
    expect($dailyStats->first()->cumulative_sessions)->toBe(7);
});
