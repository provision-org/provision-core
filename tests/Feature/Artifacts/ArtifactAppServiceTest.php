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
        ->toContain("ExecStart=/bin/bash -lc 'printf %s ".base64_encode('node server.js')." | base64 --decode | /bin/bash'")
        ->toContain('Restart=always');
});

test('buildUnit encodes commands so quotes cannot inject systemd directives', function () {
    $agent = Agent::factory()->create(['name' => 'Luna', 'harness_agent_id' => 'agent-luna']);
    $command = "node -e \"console.log('ready')\"\nEnvironment=INJECTED=true";
    $artifact = AgentArtifact::factory()->make([
        'type' => ArtifactType::App,
        'path_slug' => 'api',
        'source_dir' => 'api-app',
        'start_command' => $command,
        'port' => 7003,
    ]);

    $unit = app(ArtifactAppService::class)->buildUnit($agent, $artifact);

    expect($unit)
        ->toContain(base64_encode($command))
        ->not->toContain("\nEnvironment=INJECTED=true");
});

test('deploy writes and starts the systemd unit', function () {
    $server = Server::factory()->running()->create();
    $agent = Agent::factory()->create(['name' => 'Luna', 'server_id' => $server->id, 'harness_agent_id' => 'agent-luna']);
    $artifact = AgentArtifact::factory()->create([
        'agent_id' => $agent->id, 'team_id' => $agent->team_id,
        'type' => ArtifactType::App, 'path_slug' => 'api', 'start_command' => 'node server.js', 'port' => 7005,
    ]);
    $unit = "provision-artifact-{$agent->slug}-{$artifact->id}-{$artifact->deployment_key}.service";

    $executor = Mockery::mock(CommandExecutor::class);
    $executor->shouldReceive('writeFile')->once()
        ->with("/etc/systemd/system/{$unit}", Mockery::type('string'))
        ->ordered();
    $executor->shouldReceive('exec')->with('systemctl daemon-reload')->once()->ordered();
    $executor->shouldReceive('exec')->with("systemctl enable --now '{$unit}'")->once()->ordered();
    $executor->shouldReceive('exec')->with("systemctl restart '{$unit}'")->once()->ordered();
    $executor->shouldReceive('exec')
        ->with(Mockery::on(function (string $command) use ($unit): bool {
            expect($command)
                ->toContain("systemctl is-active --quiet '{$unit}'")
                ->toContain('ss -H -ltn "sport = :7005" | grep -q .')
                ->toContain('exit 1');

            return true;
        }))
        ->once()
        ->ordered();

    $harness = Mockery::mock(HarnessManager::class);
    $harness->shouldReceive('resolveExecutor')->andReturn($executor);
    app()->instance(HarnessManager::class, $harness);

    app(ArtifactAppService::class)->deploy($agent, $artifact);
});

test('deploy does not succeed when the unit never becomes active on its allocated port', function () {
    $server = Server::factory()->running()->create();
    $agent = Agent::factory()->create([
        'name' => 'Luna',
        'server_id' => $server->id,
        'harness_agent_id' => 'agent-luna',
    ]);
    $artifact = AgentArtifact::factory()->create([
        'agent_id' => $agent->id, 'team_id' => $agent->team_id,
        'type' => ArtifactType::App, 'path_slug' => 'api', 'start_command' => 'node server.js', 'port' => 7005,
    ]);

    $executor = Mockery::mock(CommandExecutor::class);
    $executor->shouldReceive('writeFile')->once();
    $executor->shouldReceive('exec')->with('systemctl daemon-reload')->once();
    $executor->shouldReceive('exec')->with(Mockery::pattern('/systemctl enable --now/'))->once();
    $executor->shouldReceive('exec')->with(Mockery::pattern('/systemctl restart/'))->once();
    $executor->shouldReceive('exec')
        ->with(Mockery::pattern('/systemctl is-active.*ss -H -ltn/s'))
        ->once()
        ->andThrow(new RuntimeException('Artifact app did not become ready.'));

    $harness = Mockery::mock(HarnessManager::class);
    $harness->shouldReceive('resolveExecutor')->andReturn($executor);
    app()->instance(HarnessManager::class, $harness);

    expect(fn () => app(ArtifactAppService::class)->deploy($agent, $artifact))
        ->toThrow(RuntimeException::class, 'Artifact app did not become ready.');
});

test('deploy rejects an app without an allocated port', function () {
    $server = Server::factory()->running()->create();
    $agent = Agent::factory()->create(['server_id' => $server->id]);
    $artifact = AgentArtifact::factory()->make([
        'type' => ArtifactType::App,
        'path_slug' => 'api',
        'source_dir' => 'api-app',
        'start_command' => 'node server.js',
        'port' => null,
    ]);

    expect(fn () => app(ArtifactAppService::class)->deploy($agent, $artifact))
        ->toThrow(RuntimeException::class, 'App artifacts require an allocated TCP port before deployment.');
});

test('remove stops and deletes the systemd unit', function () {
    $server = Server::factory()->running()->create();
    $agent = Agent::factory()->create(['name' => 'Luna', 'server_id' => $server->id]);
    $artifact = AgentArtifact::factory()->create([
        'agent_id' => $agent->id, 'team_id' => $agent->team_id,
        'type' => ArtifactType::App, 'path_slug' => 'api',
    ]);
    $unit = "provision-artifact-{$agent->slug}-{$artifact->id}-{$artifact->deployment_key}.service";

    $executor = Mockery::mock(CommandExecutor::class);
    $executor->shouldReceive('exec')
        ->with(Mockery::on(function (string $command) use ($unit): bool {
            expect($command)
                ->toContain("[ -e '/etc/systemd/system/{$unit}' ]")
                ->toContain("systemctl is-active --quiet '{$unit}'")
                ->toContain("systemctl disable --now '{$unit}'")
                ->not->toContain('|| true');

            return true;
        }))
        ->once();
    $executor->shouldReceive('exec')
        ->with("if systemctl is-active --quiet '{$unit}'; then echo 'Artifact unit is still active.' >&2; exit 1; fi")
        ->once();
    $executor->shouldReceive('exec')->with("rm -f '/etc/systemd/system/{$unit}'")->once();
    $executor->shouldReceive('exec')->with('systemctl daemon-reload')->once();

    $harness = Mockery::mock(HarnessManager::class);
    $harness->shouldReceive('resolveExecutor')->andReturn($executor);
    app()->instance(HarnessManager::class, $harness);

    app(ArtifactAppService::class)->remove($agent, $artifact);
});

test('remove keeps the unit file when stopping an existing unit fails', function () {
    $server = Server::factory()->running()->create();
    $agent = Agent::factory()->create(['name' => 'Luna', 'server_id' => $server->id]);
    $artifact = AgentArtifact::factory()->create([
        'agent_id' => $agent->id, 'team_id' => $agent->team_id,
        'type' => ArtifactType::App, 'path_slug' => 'api',
    ]);

    $executor = Mockery::mock(CommandExecutor::class);
    $executor->shouldReceive('exec')
        ->with(Mockery::pattern('/systemctl disable --now/'))
        ->once()
        ->andThrow(new RuntimeException('Unable to stop artifact unit.'));
    $executor->shouldNotReceive('exec')->with(Mockery::pattern('/^rm -f/'));
    $executor->shouldNotReceive('exec')->with('systemctl daemon-reload');

    $harness = Mockery::mock(HarnessManager::class);
    $harness->shouldReceive('resolveExecutor')->andReturn($executor);
    app()->instance(HarnessManager::class, $harness);

    expect(fn () => app(ArtifactAppService::class)->remove($agent, $artifact))
        ->toThrow(RuntimeException::class, 'Unable to stop artifact unit.');
});

test('removeArtifact removes every systemd revision scoped to one artifact', function () {
    $server = Server::factory()->running()->create();
    $agent = Agent::factory()->create(['name' => 'Luna', 'server_id' => $server->id]);
    $artifact = AgentArtifact::factory()->create([
        'agent_id' => $agent->id,
        'team_id' => $agent->team_id,
        'type' => ArtifactType::App,
        'path_slug' => 'api',
    ]);
    $base = "provision-artifact-{$agent->slug}-{$artifact->id}";

    $executor = Mockery::mock(CommandExecutor::class);
    $executor->shouldReceive('exec')
        ->with(Mockery::on(function (string $command) use ($base): bool {
            expect($command)
                ->toContain("'/etc/systemd/system/{$base}.service'")
                ->toContain("/etc/systemd/system/{$base}-*.service")
                ->toContain("case \"\$unit\" in {$base}.service|{$base}-*.service)")
                ->not->toContain('if [ "$unit" =');

            return true;
        }))
        ->once()
        ->ordered();
    $executor->shouldReceive('exec')->with('systemctl daemon-reload')->once()->ordered();

    $harness = Mockery::mock(HarnessManager::class);
    $harness->shouldReceive('resolveExecutor')->once()->andReturn($executor);
    app()->instance(HarnessManager::class, $harness);

    app(ArtifactAppService::class)->removeArtifact($agent, $artifact);
});

test('removeStaleRevisions preserves the active systemd revision', function () {
    $server = Server::factory()->running()->create();
    $agent = Agent::factory()->create(['name' => 'Luna', 'server_id' => $server->id]);
    $artifact = AgentArtifact::factory()->create([
        'agent_id' => $agent->id,
        'team_id' => $agent->team_id,
        'type' => ArtifactType::App,
        'path_slug' => 'api',
    ]);
    $active = "provision-artifact-{$agent->slug}-{$artifact->id}-{$artifact->deployment_key}.service";

    $executor = Mockery::mock(CommandExecutor::class);
    $executor->shouldReceive('exec')
        ->with(Mockery::on(function (string $command) use ($active): bool {
            expect($command)->toContain("if [ \"\$unit\" = '{$active}' ]; then continue; fi");

            return true;
        }))
        ->once()
        ->ordered();
    $executor->shouldReceive('exec')->with('systemctl daemon-reload')->once()->ordered();

    $harness = Mockery::mock(HarnessManager::class);
    $harness->shouldReceive('resolveExecutor')->once()->andReturn($executor);
    app()->instance(HarnessManager::class, $harness);

    app(ArtifactAppService::class)->removeStaleRevisions($agent, $artifact);
});

test('removeAgent idempotently stops verifies and removes only units scoped to the agent slug', function () {
    $server = Server::factory()->running()->create();
    $agent = Agent::factory()->create(['name' => 'Luna', 'server_id' => $server->id]);
    $unitPattern = "provision-artifact-{$agent->slug}-*.service";

    $executor = Mockery::mock(CommandExecutor::class);
    $executor->shouldReceive('exec')
        ->with(Mockery::on(function (string $command) use ($unitPattern): bool {
            expect($command)
                ->toContain("for unit_path in /etc/systemd/system/{$unitPattern}")
                ->toContain('[ -e "$unit_path" ] || [ -L "$unit_path" ] || continue')
                ->toContain("case \"\$unit\" in {$unitPattern})")
                ->toContain('systemctl disable --now "$unit" || exit 1')
                ->toContain('systemctl is-active --quiet "$unit"')
                ->toContain('rm -f -- "$unit_path" || exit 1')
                ->not->toContain('/etc/systemd/system/provision-artifact-*.service');

            return true;
        }))
        ->once()
        ->ordered();
    $executor->shouldReceive('exec')->with('systemctl daemon-reload')->once()->ordered();

    $harness = Mockery::mock(HarnessManager::class);
    $harness->shouldReceive('resolveExecutor')->andReturn($executor);
    app()->instance(HarnessManager::class, $harness);

    app(ArtifactAppService::class)->removeAgent($agent);
});

test('removeAgent keeps unit files when stopping a scoped unit fails', function () {
    $server = Server::factory()->running()->create();
    $agent = Agent::factory()->create(['name' => 'Luna', 'server_id' => $server->id]);

    $executor = Mockery::mock(CommandExecutor::class);
    $executor->shouldReceive('exec')
        ->with(Mockery::pattern('/for unit_path in \/etc\/systemd\/system\/provision-artifact-/'))
        ->once()
        ->andThrow(new RuntimeException('Unable to stop stale artifact unit.'));
    $executor->shouldNotReceive('exec')->with('systemctl daemon-reload');

    $harness = Mockery::mock(HarnessManager::class);
    $harness->shouldReceive('resolveExecutor')->andReturn($executor);
    app()->instance(HarnessManager::class, $harness);

    expect(fn () => app(ArtifactAppService::class)->removeAgent($agent))
        ->toThrow(RuntimeException::class, 'Unable to stop stale artifact unit.');
});

test('removeAgent rejects an unsafe slug before resolving a remote executor', function () {
    $server = Server::factory()->running()->create();
    $agent = Agent::factory()->create(['server_id' => $server->id]);
    $agent->forceFill(['slug' => 'unsafe; systemctl stop caddy']);

    $harness = Mockery::mock(HarnessManager::class);
    $harness->shouldNotReceive('resolveExecutor');
    app()->instance(HarnessManager::class, $harness);

    expect(fn () => app(ArtifactAppService::class)->removeAgent($agent))
        ->toThrow(RuntimeException::class, 'Cannot remove artifact units for an invalid agent slug.');
});
