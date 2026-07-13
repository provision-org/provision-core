<?php

use App\Contracts\CommandExecutor;
use App\Contracts\HarnessDriver;
use App\Jobs\RefreshAgentEmailJob;
use App\Models\Agent;
use App\Models\Server;
use App\Services\HarnessManager;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('it clears the guarded files then re-syncs the agent', function () {
    $server = Server::factory()->running()->create();
    $agent = Agent::factory()->create([
        'server_id' => $server->id,
        'harness_agent_id' => 'agent-abc',
    ]);

    $executor = Mockery::mock(CommandExecutor::class);
    $executor->shouldReceive('exec')->once()
        ->with('rm -f /root/.openclaw/agents/agent-abc/.gitconfig /root/.openclaw/agents/agent-abc/ONBOARDING.md');

    $driver = Mockery::mock(HarnessDriver::class);
    $driver->shouldReceive('updateAgent')->once()
        ->with(Mockery::on(fn ($a) => $a->is($agent)), $executor);

    $harness = Mockery::mock(HarnessManager::class);
    $harness->shouldReceive('resolveExecutor')->andReturn($executor);
    $harness->shouldReceive('forAgent')->andReturn($driver);
    app()->instance(HarnessManager::class, $harness);

    (new RefreshAgentEmailJob($agent))->handle($harness);
});

test('it no-ops when the agent has no server', function () {
    $agent = Agent::factory()->create(['server_id' => null]);

    $harness = Mockery::mock(HarnessManager::class);
    $harness->shouldReceive('resolveExecutor')->never();

    (new RefreshAgentEmailJob($agent))->handle($harness);

    // resolveExecutor was never reached — Mockery verifies on teardown.
    expect($agent->fresh()->server_id)->toBeNull();
});
