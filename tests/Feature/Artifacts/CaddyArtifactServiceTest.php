<?php

use App\Contracts\CommandExecutor;
use App\Enums\ArtifactType;
use App\Models\Agent;
use App\Models\AgentArtifact;
use App\Models\Server;
use App\Services\CaddyArtifactService;
use App\Services\HarnessManager;
use App\Support\OpenClawGatewayEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => config(['cloudflare.artifact_domain' => 'provisionagents.com']));

final class RecordingArtifactCaddyExecutor implements CommandExecutor
{
    /** @var list<string> */
    public array $commands = [];

    /** @var array<string, string> */
    public array $writes = [];

    public ?string $failWhenCommandContains = null;

    public ?int $failOnReloadNumber = null;

    private int $reloads = 0;

    public function __construct(public string $currentCaddyfile = '') {}

    public function exec(string $command): string
    {
        $this->commands[] = $command;

        if ($command === "if [ -f '/etc/caddy/Caddyfile' ]; then cat '/etc/caddy/Caddyfile'; fi") {
            return $this->currentCaddyfile;
        }

        if (str_contains($command, 'systemctl reload caddy')) {
            $this->reloads++;

            if ($this->failOnReloadNumber === $this->reloads) {
                throw new RuntimeException('Simulated Caddy failure.');
            }
        }

        if ($this->failWhenCommandContains !== null && str_contains($command, $this->failWhenCommandContains)) {
            throw new RuntimeException('Simulated Caddy failure.');
        }

        return '';
    }

    public function execWithRetry(string $command, int $maxAttempts = 3, int $baseDelayMs = 2000): string
    {
        return $this->exec($command);
    }

    public function writeFile(string $path, string $content): void
    {
        $this->writes[$path] = $content;
    }

    public function readFile(string $path): string
    {
        return '';
    }

    public function execScript(string $script): string
    {
        return $this->exec($script);
    }
}

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
        ->toContain("{$agent->slug}.provisionagents.com {")
        ->toContain('tls {')
        ->toContain('on_demand')
        ->toContain('handle_path /dashboard/* {')
        ->toContain("root * \"/srv/provision-artifacts/{$agent->slug}/{$dash->id}/{$dash->deployment_key}\"")
        ->toContain('handle_path /report/* {')
        ->toContain("root * \"/srv/provision-artifacts/{$agent->slug}/{$report->id}/{$report->deployment_key}\"")
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
        ->toContain('reverse_proxy localhost:7002')
        ->toContain('header_up X-Forwarded-Prefix /api');
});

test('syncAgent writes the site file and reloads caddy', function () {
    $server = Server::factory()->running()->create();
    $agent = Agent::factory()->create(['name' => 'Luna', 'server_id' => $server->id, 'harness_agent_id' => 'agent-luna']);
    AgentArtifact::factory()->live()->create([
        'agent_id' => $agent->id, 'team_id' => $agent->team_id, 'path_slug' => 'dashboard',
    ]);

    $executor = new RecordingArtifactCaddyExecutor(OpenClawGatewayEndpoint::caddyfile($server));

    $harness = Mockery::mock(HarnessManager::class);
    $harness->shouldReceive('resolveExecutor')->andReturn($executor);
    app()->instance(HarnessManager::class, $harness);

    app(CaddyArtifactService::class)->syncAgent($agent);

    $sitePath = "/etc/caddy/sites/{$agent->slug}.caddy";
    $candidate = collect(array_keys($executor->writes))->first(
        fn (string $path): bool => str_starts_with($path, $sitePath.'.provision-artifact-'),
    );
    $transaction = collect($executor->commands)->first(
        fn (string $command): bool => str_contains($command, "mv '{$candidate}' '{$sitePath}'"),
    );
    $lockedTransactions = collect($executor->commands)->filter(
        fn (string $command): bool => str_contains($command, 'flock -x 9'),
    );

    expect($candidate)->toStartWith($sitePath.'.provision-artifact-')
        ->and($executor->writes[$candidate])->toContain("{$agent->slug}.provisionagents.com")
        ->and($executor->commands)->toContain('mkdir -p /etc/caddy/sites')
        ->and($executor->commands)->toContain("caddy validate --config '{$candidate}' --adapter caddyfile")
        ->and($transaction)->toContain("exec 9>'".OpenClawGatewayEndpoint::CADDY_LOCK_FILE."'")
        ->toContain("cp -p '{$sitePath}' '{$sitePath}.provision-previous'")
        ->toContain("touch '{$sitePath}.provision-previous-absent'")
        ->toContain("caddy validate --config '/etc/caddy/Caddyfile' --adapter caddyfile")
        ->toContain('systemctl reload caddy')
        ->and($lockedTransactions)->toHaveCount(2)
        ->each->toContain("exec 9>'".OpenClawGatewayEndpoint::CADDY_LOCK_FILE."'");
});

test('syncAgent removes the site file when the agent has no live artifacts', function () {
    $server = Server::factory()->running()->create();
    $agent = Agent::factory()->create(['name' => 'Luna', 'server_id' => $server->id]);

    $executor = new RecordingArtifactCaddyExecutor(OpenClawGatewayEndpoint::caddyfile($server));

    $harness = Mockery::mock(HarnessManager::class);
    $harness->shouldReceive('resolveExecutor')->andReturn($executor);
    app()->instance(HarnessManager::class, $harness);

    app(CaddyArtifactService::class)->syncAgent($agent);

    $sitePath = "/etc/caddy/sites/{$agent->slug}.caddy";
    $transaction = collect($executor->commands)->first(
        fn (string $command): bool => str_contains($command, "rm -f '{$sitePath}'"),
    );

    expect($executor->commands)->toContain('mkdir -p /etc/caddy/sites')
        ->and($transaction)->toContain("exec 9>'".OpenClawGatewayEndpoint::CADDY_LOCK_FILE."'")
        ->toContain("cp -p '{$sitePath}' '{$sitePath}.provision-previous'")
        ->toContain("rm -f '{$sitePath}'")
        ->toContain("cp -p '{$sitePath}.provision-previous' '{$sitePath}'")
        ->toContain("caddy validate --config '/etc/caddy/Caddyfile' --adapter caddyfile")
        ->toContain('systemctl reload caddy')
        ->not->toContain("rm -f '{$sitePath}.provision-previous'");
});

test('syncAgent keeps the active site when candidate validation fails', function () {
    $server = Server::factory()->running()->create();
    $agent = Agent::factory()->create(['name' => 'Luna', 'server_id' => $server->id]);
    AgentArtifact::factory()->live()->create([
        'agent_id' => $agent->id,
        'team_id' => $agent->team_id,
        'path_slug' => 'dashboard',
    ]);

    $executor = new RecordingArtifactCaddyExecutor(OpenClawGatewayEndpoint::caddyfile($server));
    $executor->failWhenCommandContains = "caddy validate --config '/etc/caddy/sites/";

    $harness = Mockery::mock(HarnessManager::class);
    $harness->shouldReceive('resolveExecutor')->andReturn($executor);
    app()->instance(HarnessManager::class, $harness);

    expect(fn () => app(CaddyArtifactService::class)->syncAgent($agent))
        ->toThrow(RuntimeException::class, 'Simulated Caddy failure.');

    $sitePath = "/etc/caddy/sites/{$agent->slug}.caddy";
    $candidate = collect(array_keys($executor->writes))->first(
        fn (string $path): bool => str_starts_with($path, $sitePath.'.provision-artifact-'),
    );

    expect(collect($executor->commands)->contains(
        fn (string $command): bool => $command === "mv '{$candidate}' '{$sitePath}'",
    ))->toBeFalse()
        ->and(collect($executor->commands)->contains(
            fn (string $command): bool => str_contains($command, "rm -f '{$candidate}'"),
        ))->toBeTrue();
});

test('syncAgent retains the active site backup when mutation or rollback outcome is ambiguous', function () {
    $server = Server::factory()->running()->create();
    $agent = Agent::factory()->create(['name' => 'Luna', 'server_id' => $server->id]);
    AgentArtifact::factory()->live()->create([
        'agent_id' => $agent->id,
        'team_id' => $agent->team_id,
        'path_slug' => 'dashboard',
    ]);

    $executor = new RecordingArtifactCaddyExecutor(OpenClawGatewayEndpoint::caddyfile($server));
    $executor->failOnReloadNumber = 2;

    $harness = Mockery::mock(HarnessManager::class);
    $harness->shouldReceive('resolveExecutor')->andReturn($executor);
    app()->instance(HarnessManager::class, $harness);

    expect(fn () => app(CaddyArtifactService::class)->syncAgent($agent))
        ->toThrow(RuntimeException::class, 'Simulated Caddy failure.');

    $sitePath = "/etc/caddy/sites/{$agent->slug}.caddy";
    $transaction = collect($executor->commands)->first(
        fn (string $command): bool => str_contains($command, "cp -p '{$sitePath}' '{$sitePath}.provision-previous'"),
    );

    expect($transaction)
        ->toContain("exec 9>'".OpenClawGatewayEndpoint::CADDY_LOCK_FILE."'")
        ->toContain("cp -p '{$sitePath}.provision-previous' '{$sitePath}'")
        ->toContain("touch '{$sitePath}.provision-previous-absent'")
        ->not->toContain("rm -f '{$sitePath}.provision-previous'")
        ->and(collect($executor->commands)->last())->toBe($transaction);
});

test('syncAgent repairs a legacy root Caddyfile before activating an artifact site', function () {
    $server = Server::factory()->running()->create(['ipv4_address' => '203.0.113.42']);
    $agent = Agent::factory()->create(['name' => 'Luna', 'server_id' => $server->id]);
    AgentArtifact::factory()->live()->create([
        'agent_id' => $agent->id,
        'team_id' => $agent->team_id,
        'path_slug' => 'dashboard',
    ]);

    $executor = new RecordingArtifactCaddyExecutor("203-0-113-42.sslip.io {\n    import /etc/caddy/conf.d/*.caddy\n}");
    $harness = Mockery::mock(HarnessManager::class);
    $harness->shouldReceive('resolveExecutor')->andReturn($executor);
    app()->instance(HarnessManager::class, $harness);

    app(CaddyArtifactService::class)->syncAgent($agent);

    $rootCandidate = collect($executor->writes)->keys()->first(
        fn (string $path): bool => str_starts_with($path, '/etc/caddy/Caddyfile.provision-mobile-'),
    );

    expect($executor->writes[$rootCandidate])
        ->toContain('import /etc/caddy/sites/*.caddy')
        ->toContain('gateway.203-0-113-42.sslip.io {');
});
