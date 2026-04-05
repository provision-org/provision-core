<?php

use App\Contracts\CommandExecutor;
use App\Jobs\RemoveAgentFromServerJob;
use App\Jobs\RestartGatewayJob;
use App\Models\Agent;
use App\Models\AgentSlackConnection;
use App\Models\Server;
use App\Models\Team;
use App\Services\ConfigPatchService;
use App\Services\HarnessManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

test('it removes agent from config and workspace directory via SSH', function () {
    Bus::fake([RestartGatewayJob::class]);

    $team = Team::factory()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'harness_agent_id' => 'agent-remove-1',
    ]);

    $executor = Mockery::mock(CommandExecutor::class);
    $executor->shouldReceive('exec')->twice(); // remove agent patch + rm -rf workspace

    $harnessManager = Mockery::mock(HarnessManager::class);
    $harnessManager->shouldReceive('resolveExecutor')->once()->andReturn($executor);

    $configPatchService = Mockery::mock(ConfigPatchService::class);
    $configPatchService->shouldReceive('buildRemoveAgentPatch')
        ->once()
        ->with('agent-remove-1')
        ->andReturn('remove-agent-cmd');

    (new RemoveAgentFromServerJob($server, 'agent-remove-1', false))->handle($harnessManager, $configPatchService);

    Bus::assertDispatched(RestartGatewayJob::class);
});

test('it removes slack account when slack connection exists', function () {
    Bus::fake([RestartGatewayJob::class]);

    $team = Team::factory()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);
    $agent = Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'harness_agent_id' => 'agent-remove-slack',
    ]);
    AgentSlackConnection::factory()->create(['agent_id' => $agent->id]);

    $executor = Mockery::mock(CommandExecutor::class);
    $executor->shouldReceive('exec')->times(3); // remove agent + remove slack + rm -rf

    $harnessManager = Mockery::mock(HarnessManager::class);
    $harnessManager->shouldReceive('resolveExecutor')->once()->andReturn($executor);

    $configPatchService = Mockery::mock(ConfigPatchService::class);
    $configPatchService->shouldReceive('buildRemoveAgentPatch')->once()->andReturn('remove-agent');
    $configPatchService->shouldReceive('buildRemoveSlackTokensPatch')->once()->andReturn('remove-slack');

    (new RemoveAgentFromServerJob($server, 'agent-remove-slack', true))->handle($harnessManager, $configPatchService);
});
