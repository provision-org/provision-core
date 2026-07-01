<?php

use App\Contracts\CommandExecutor;
use App\Enums\ArtifactType;
use App\Models\Agent;
use App\Models\AgentArtifact;
use App\Models\Server;
use App\Services\CaddyArtifactService;
use App\Services\HarnessManager;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => config(['cloudflare.artifact_domain' => 'provisionagents.com']));

test('buildSiteConfig serves each static artifact from its public dir', function () {
    $agent = Agent::factory()->create(['name' => 'Luna', 'harness_agent_id' => 'agent-luna']);
    $dash = AgentArtifact::factory()->live()->create([
        'agent_id' => $agent->id, 'team_id' => $agent->team_id,
        'path_slug' => 'dashboard', 'source_dir' => 'dashboard',
    ]);
    $report = AgentArtifact::factory()->live()->create([
        'agent_id' => $agent->id, 'team_id' => $agent->team_id,
        'path_slug' => 'report', 'source_dir' => 'q3-report',
    ]);

    $config = app(CaddyArtifactService::class)->buildSiteConfig(
        $agent,
        collect([$dash, $report]),
    );

    expect($config)
        ->toContain('luna.provisionagents.com {')
        ->toContain('tls {')
        ->toContain('on_demand')
        ->toContain('handle_path /dashboard/* {')
        ->toContain('root * /root/.openclaw/agents/agent-luna/public/dashboard')
        ->toContain('handle_path /report/* {')
        ->toContain('root * /root/.openclaw/agents/agent-luna/public/q3-report')
        ->toContain('file_server');
});

test('buildSiteConfig reverse-proxies app artifacts to their port', function () {
    $agent = Agent::factory()->create(['name' => 'Luna', 'harness_agent_id' => 'agent-luna']);
    $app = AgentArtifact::factory()->live()->create([
        'agent_id' => $agent->id, 'team_id' => $agent->team_id,
        'type' => ArtifactType::App, 'path_slug' => 'api', 'port' => 7002,
    ]);

    $config = app(CaddyArtifactService::class)->buildSiteConfig($agent, collect([$app]));

    expect($config)
        ->toContain('handle_path /api/* {')
        ->toContain('reverse_proxy localhost:7002');
});

test('syncAgent writes the site file and reloads caddy', function () {
    $server = Server::factory()->running()->create();
    $agent = Agent::factory()->create(['name' => 'Luna', 'server_id' => $server->id, 'harness_agent_id' => 'agent-luna']);
    AgentArtifact::factory()->live()->create([
        'agent_id' => $agent->id, 'team_id' => $agent->team_id, 'path_slug' => 'dashboard',
    ]);

    $executor = Mockery::mock(CommandExecutor::class);
    $executor->shouldReceive('writeFile')->once()
        ->with('/etc/caddy/sites/luna.caddy', Mockery::pattern('/luna\.provisionagents\.com/'));
    $executor->shouldReceive('exec')->once()->with(Mockery::pattern('/reload caddy/'));

    $harness = Mockery::mock(HarnessManager::class);
    $harness->shouldReceive('resolveExecutor')->andReturn($executor);
    app()->instance(HarnessManager::class, $harness);

    app(CaddyArtifactService::class)->syncAgent($agent);
});

test('syncAgent removes the site file when the agent has no live artifacts', function () {
    $server = Server::factory()->running()->create();
    $agent = Agent::factory()->create(['name' => 'Luna', 'server_id' => $server->id]);

    $executor = Mockery::mock(CommandExecutor::class);
    $executor->shouldReceive('exec')->once()->with('rm -f /etc/caddy/sites/luna.caddy');
    $executor->shouldReceive('exec')->once()->with(Mockery::pattern('/reload caddy/'));

    $harness = Mockery::mock(HarnessManager::class);
    $harness->shouldReceive('resolveExecutor')->andReturn($executor);
    app()->instance(HarnessManager::class, $harness);

    app(CaddyArtifactService::class)->syncAgent($agent);
});
