<?php

use App\Contracts\CommandExecutor;
use App\Contracts\HarnessDriver;
use App\Jobs\RestartGatewayJob;
use App\Jobs\UpdateAgentOnServerJob;
use App\Models\Agent;
use App\Models\Server;
use App\Models\Team;
use App\Services\AgentInstallScriptService;
use App\Services\HarnessManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

test('it updates agent config and workspace files via SSH', function () {
    Bus::fake([RestartGatewayJob::class]);

    $team = Team::factory()->subscribed()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'harness_agent_id' => 'agent-789',
        'system_prompt' => 'Updated system prompt.',
        'identity' => 'Updated identity.',
    ]);

    $executor = Mockery::mock(CommandExecutor::class);

    $driver = Mockery::mock(HarnessDriver::class);
    $driver->shouldReceive('updateAgent')
        ->once()
        ->with(Mockery::on(fn ($a) => $a->id === $agent->id), $executor)
        ->andReturnUsing(function (Agent $a) {
            $a->update([
                'config_snapshot' => [
                    'agents' => [
                        'list' => [[
                            'id' => $a->harness_agent_id,
                            'name' => $a->name,
                            'workspace' => '/root/.openclaw/agents/'.$a->harness_agent_id,
                            'agentDir' => '/root/.openclaw/agents/'.$a->harness_agent_id.'/agent',
                            'model' => $a->openclawModel(),
                        ]],
                    ],
                ],
                'is_syncing' => false,
                'last_synced_at' => now(),
            ]);
        });

    $harnessManager = Mockery::mock(HarnessManager::class);
    $harnessManager->shouldReceive('resolveExecutor')->once()->andReturn($executor);
    $harnessManager->shouldReceive('forAgent')->once()->andReturn($driver);

    (new UpdateAgentOnServerJob($agent))->handle($harnessManager);

    $agent->refresh();

    expect($agent->config_snapshot)->toBeArray()
        ->and($agent->config_snapshot['agents']['list'][0])->toMatchArray([
            'id' => 'agent-789',
            'name' => $agent->name,
            'workspace' => '/root/.openclaw/agents/agent-789',
            'agentDir' => '/root/.openclaw/agents/agent-789/agent',
            'model' => $agent->openclawModel(),
        ])
        ->and($agent->is_syncing)->toBeFalse()
        ->and($agent->last_synced_at)->not->toBeNull();
});

test('it includes git isolation env vars in agent env', function () {
    $agent = Agent::factory()->create(['harness_agent_id' => 'test-agent-123']);
    $env = AgentInstallScriptService::buildAgentEnv($agent);

    expect($env)
        ->toContain('GH_CONFIG_DIR=/root/.openclaw/agents/test-agent-123/.gh')
        ->toContain('GIT_CONFIG_GLOBAL=/root/.openclaw/agents/test-agent-123/.gitconfig');
});
