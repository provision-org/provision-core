<?php

use App\Contracts\CommandExecutor;
use App\Enums\AgentStatus;
use App\Enums\HarnessType;
use App\Models\Agent;
use App\Models\Server;
use App\Services\HarnessManager;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('it rewrites the caddy route to the frozen profile name for a drifted agent', function () {
    $server = Server::factory()->running()->create();
    // Drifted: frozen name (agent-kate-w) differs from current name.
    $agent = Agent::factory()->create([
        'server_id' => $server->id,
        'harness_type' => HarnessType::OpenClaw,
        'status' => AgentStatus::Active,
        'name' => 'Kate Wilson',
        'browser_display_num' => 1,
        'browser_profile_name' => 'agent-kate-w',
    ]);

    $executor = Mockery::mock(CommandExecutor::class);
    // Route on the box is stale/empty for the frozen name.
    $executor->shouldReceive('exec')
        ->with('cat /etc/caddy/conf.d/agent-kate-w.caddy 2>/dev/null || true')
        ->andReturn('');
    $executor->shouldReceive('writeFile')->once()
        ->with('/etc/caddy/conf.d/agent-kate-w.caddy', Mockery::pattern('#handle_path /browser/agent-kate-w/\* \{\n    reverse_proxy localhost:6081\n\}#'));
    $executor->shouldReceive('exec')->once()->with(Mockery::pattern('/reload caddy/'));

    $harness = Mockery::mock(HarnessManager::class);
    $harness->shouldReceive('resolveExecutor')->andReturn($executor);
    app()->instance(HarnessManager::class, $harness);

    $this->artisan('agents:repair-browser-routes')
        ->assertSuccessful();

    expect($agent->fresh()->browser_profile_name)->toBe('agent-kate-w');
});

test('dry-run writes nothing', function () {
    $server = Server::factory()->running()->create();
    Agent::factory()->create([
        'server_id' => $server->id,
        'harness_type' => HarnessType::OpenClaw,
        'status' => AgentStatus::Active,
        'name' => 'Scout',
        'browser_display_num' => 2,
        'browser_profile_name' => 'agent-scout',
    ]);

    $executor = Mockery::mock(CommandExecutor::class);
    $executor->shouldReceive('exec')
        ->with('cat /etc/caddy/conf.d/agent-scout.caddy 2>/dev/null || true')
        ->andReturn('');
    $executor->shouldReceive('writeFile')->never();
    $executor->shouldReceive('exec')->with(Mockery::pattern('/reload caddy/'))->never();

    $harness = Mockery::mock(HarnessManager::class);
    $harness->shouldReceive('resolveExecutor')->andReturn($executor);
    app()->instance(HarnessManager::class, $harness);

    $this->artisan('agents:repair-browser-routes --dry-run')->assertSuccessful();
});

test('an already-correct route is left untouched', function () {
    $server = Server::factory()->running()->create();
    Agent::factory()->create([
        'server_id' => $server->id,
        'harness_type' => HarnessType::OpenClaw,
        'status' => AgentStatus::Active,
        'name' => 'Buddy',
        'browser_display_num' => 3,
        'browser_profile_name' => 'agent-buddy',
    ]);

    $current = "handle_path /browser/agent-buddy/* {\n    reverse_proxy localhost:6083\n}\n";

    $executor = Mockery::mock(CommandExecutor::class);
    $executor->shouldReceive('exec')
        ->with('cat /etc/caddy/conf.d/agent-buddy.caddy 2>/dev/null || true')
        ->andReturn($current);
    $executor->shouldReceive('writeFile')->never();
    $executor->shouldReceive('exec')->once()->with(Mockery::pattern('/reload caddy/'));

    $harness = Mockery::mock(HarnessManager::class);
    $harness->shouldReceive('resolveExecutor')->andReturn($executor);
    app()->instance(HarnessManager::class, $harness);

    $this->artisan('agents:repair-browser-routes')->assertSuccessful();
});
