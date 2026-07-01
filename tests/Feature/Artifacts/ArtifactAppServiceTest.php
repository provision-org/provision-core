<?php

use App\Contracts\CommandExecutor;
use App\Enums\ArtifactType;
use App\Models\Agent;
use App\Models\AgentArtifact;
use App\Models\Server;
use App\Services\ArtifactAppService;
use App\Services\HarnessManager;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('allocatePort starts at the base and increments per server', function () {
    $server = Server::factory()->running()->create();
    $agent = Agent::factory()->create(['server_id' => $server->id]);

    expect(app(ArtifactAppService::class)->allocatePort($server))->toBe(7000);

    AgentArtifact::factory()->create([
        'agent_id' => $agent->id, 'team_id' => $agent->team_id,
        'type' => ArtifactType::App, 'port' => 7000,
    ]);

    expect(app(ArtifactAppService::class)->allocatePort($server))->toBe(7001);
});

test('buildUnit runs the start command on the allocated port from the artifact dir', function () {
    $agent = Agent::factory()->create(['name' => 'Luna', 'harness_agent_id' => 'agent-luna']);
    $artifact = AgentArtifact::factory()->make([
        'type' => ArtifactType::App,
        'path_slug' => 'api',
        'source_dir' => 'api-app',
        'start_command' => 'node server.js',
        'port' => 7003,
    ]);

    $unit = app(ArtifactAppService::class)->buildUnit($agent, $artifact);

    expect($unit)
        ->toContain('WorkingDirectory=/root/.openclaw/agents/agent-luna/public/api-app')
        ->toContain('Environment=PORT=7003')
        ->toContain("ExecStart=/bin/bash -lc 'node server.js'")
        ->toContain('Restart=always');
});

test('deploy writes and starts the systemd unit', function () {
    $server = Server::factory()->running()->create();
    $agent = Agent::factory()->create(['name' => 'Luna', 'server_id' => $server->id, 'harness_agent_id' => 'agent-luna']);
    $artifact = AgentArtifact::factory()->create([
        'agent_id' => $agent->id, 'team_id' => $agent->team_id,
        'type' => ArtifactType::App, 'path_slug' => 'api', 'start_command' => 'node server.js', 'port' => 7005,
    ]);

    $executor = Mockery::mock(CommandExecutor::class);
    $executor->shouldReceive('writeFile')->once()
        ->with('/etc/systemd/system/provision-artifact-luna-api.service', Mockery::type('string'));
    $executor->shouldReceive('exec')->with('systemctl daemon-reload')->once();
    $executor->shouldReceive('exec')->with('systemctl enable --now provision-artifact-luna-api.service')->once();
    $executor->shouldReceive('exec')->with('systemctl restart provision-artifact-luna-api.service')->once();

    $harness = Mockery::mock(HarnessManager::class);
    $harness->shouldReceive('resolveExecutor')->andReturn($executor);
    app()->instance(HarnessManager::class, $harness);

    app(ArtifactAppService::class)->deploy($agent, $artifact);
});

test('remove stops and deletes the systemd unit', function () {
    $server = Server::factory()->running()->create();
    $agent = Agent::factory()->create(['name' => 'Luna', 'server_id' => $server->id]);
    $artifact = AgentArtifact::factory()->create([
        'agent_id' => $agent->id, 'team_id' => $agent->team_id,
        'type' => ArtifactType::App, 'path_slug' => 'api',
    ]);

    $executor = Mockery::mock(CommandExecutor::class);
    $executor->shouldReceive('exec')->with(Mockery::pattern('/disable --now provision-artifact-luna-api\.service/'))->once();
    $executor->shouldReceive('exec')->with('rm -f /etc/systemd/system/provision-artifact-luna-api.service')->once();
    $executor->shouldReceive('exec')->with('systemctl daemon-reload')->once();

    $harness = Mockery::mock(HarnessManager::class);
    $harness->shouldReceive('resolveExecutor')->andReturn($executor);
    app()->instance(HarnessManager::class, $harness);

    app(ArtifactAppService::class)->remove($agent, $artifact);
});
