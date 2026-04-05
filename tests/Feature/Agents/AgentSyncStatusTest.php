<?php

use App\Contracts\CommandExecutor;
use App\Contracts\HarnessDriver;
use App\Jobs\RestartGatewayJob;
use App\Jobs\UpdateAgentOnServerJob;
use App\Models\Agent;
use App\Models\Server;
use App\Services\HarnessManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

test('UpdateAgentOnServerJob clears is_syncing and sets last_synced_at on success', function () {
    $server = Server::factory()->running()->create();
    $agent = Agent::factory()->create([
        'server_id' => $server->id,
        'is_syncing' => true,
        'harness_agent_id' => 'test-agent-id',
    ]);

    $executor = Mockery::mock(CommandExecutor::class);

    $driver = Mockery::mock(HarnessDriver::class);
    $driver->shouldReceive('updateAgent')
        ->once()
        ->andReturnUsing(function (Agent $a) {
            $a->update([
                'is_syncing' => false,
                'last_synced_at' => now(),
            ]);
        });

    $harnessManager = Mockery::mock(HarnessManager::class);
    $harnessManager->shouldReceive('resolveExecutor')->andReturn($executor);
    $harnessManager->shouldReceive('forAgent')->andReturn($driver);

    Bus::fake([RestartGatewayJob::class]);

    (new UpdateAgentOnServerJob($agent))->handle($harnessManager);

    $agent->refresh();

    expect($agent->is_syncing)->toBeFalse()
        ->and($agent->last_synced_at)->not->toBeNull();
});

test('UpdateAgentOnServerJob clears is_syncing on failure', function () {
    $server = Server::factory()->running()->create();
    $agent = Agent::factory()->create([
        'server_id' => $server->id,
        'is_syncing' => true,
        'harness_agent_id' => 'test-agent-id',
    ]);

    $job = new UpdateAgentOnServerJob($agent);
    $job->failed(new RuntimeException('SSH connection failed'));

    expect($agent->fresh()->is_syncing)->toBeFalse();
});

test('agent defaults to is_syncing false', function () {
    $agent = Agent::factory()->create();

    // Refresh from DB to get column defaults
    $agent->refresh();

    expect($agent->is_syncing)->toBeFalse()
        ->and($agent->last_synced_at)->toBeNull();
});
