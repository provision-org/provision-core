<?php

use App\Contracts\CommandExecutor;
use App\Models\Agent;
use App\Models\AgentArtifact;
use App\Models\Server;
use App\Services\ArtifactStaticService;
use App\Services\HarnessManager;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

final class RecordingArtifactStaticExecutor implements CommandExecutor
{
    /** @var list<string> */
    public array $commands = [];

    public ?Closure $failWhen = null;

    public function exec(string $command): string
    {
        $this->commands[] = $command;

        if ($this->failWhen && ($this->failWhen)($command)) {
            throw new RuntimeException('Simulated staging failure.');
        }

        return '';
    }

    public function execWithRetry(string $command, int $maxAttempts = 3, int $baseDelayMs = 2000): string
    {
        return $this->exec($command);
    }

    public function writeFile(string $path, string $content): void {}

    public function readFile(string $path): string
    {
        return '';
    }

    public function execScript(string $script): string
    {
        return $this->exec($script);
    }
}

function staticArtifactFixture(): array
{
    $server = Server::factory()->running()->create();
    $agent = Agent::factory()->create([
        'server_id' => $server->id,
        'harness_agent_id' => 'agent-luna',
    ]);
    $artifact = AgentArtifact::factory()->create([
        'agent_id' => $agent->id,
        'team_id' => $agent->team_id,
        'path_slug' => 'dashboard',
        'source_dir' => 'reports/dashboard',
        'deployment_key' => 'a1b2c3d4e5f60708',
    ]);

    return [$agent, $artifact];
}

test('static deploy atomically stages only the selected public directory under srv', function () {
    [$agent, $artifact] = staticArtifactFixture();
    $executor = new RecordingArtifactStaticExecutor;
    $harness = Mockery::mock(HarnessManager::class);
    $harness->shouldReceive('resolveExecutor')->once()->andReturn($executor);
    app()->instance(HarnessManager::class, $harness);

    $service = app(ArtifactStaticService::class);
    $service->deploy($agent, $artifact);

    $source = "'/root/.openclaw/agents/agent-luna/public/reports/dashboard'";
    $target = "'/srv/provision-artifacts/{$agent->slug}/{$artifact->id}/a1b2c3d4e5f60708'";

    expect($service->publishedDirectory($agent, $artifact))
        ->toBe("/srv/provision-artifacts/{$agent->slug}/{$artifact->id}/a1b2c3d4e5f60708")
        ->and($executor->commands)->toContain("test -d {$source}")
        ->and(collect($executor->commands)->contains(
            fn (string $command): bool => str_contains($command, "find {$source}")
                && str_contains($command, '! \\( -type f -o -type d \\)'),
        ))->toBeTrue()
        ->and(collect($executor->commands)->contains(
            fn (string $command): bool => str_starts_with($command, "mv '/srv/provision-artifacts/")
                && str_ends_with($command, " {$target}"),
        ))->toBeTrue();
});

test('static deploy restores the previous directory when activation fails', function () {
    [$agent, $artifact] = staticArtifactFixture();
    $executor = new RecordingArtifactStaticExecutor;
    $executor->failWhen = fn (string $command): bool => str_starts_with($command, 'mv ')
        && str_contains($command, '.candidate-')
        && str_ends_with($command, "'/srv/provision-artifacts/{$agent->slug}/{$artifact->id}/a1b2c3d4e5f60708'");

    $harness = Mockery::mock(HarnessManager::class);
    $harness->shouldReceive('resolveExecutor')->once()->andReturn($executor);
    app()->instance(HarnessManager::class, $harness);

    expect(fn () => app(ArtifactStaticService::class)->deploy($agent, $artifact))
        ->toThrow(RuntimeException::class, 'Simulated staging failure.');

    expect(collect($executor->commands)->contains(
        fn (string $command): bool => str_contains($command, 'if [ -e')
            && str_contains($command, '.backup-')
            && str_contains($command, 'rm -rf --'),
    ))->toBeTrue();
});

test('static deploy retains the previous backup when rollback itself fails', function () {
    [$agent, $artifact] = staticArtifactFixture();
    $executor = new RecordingArtifactStaticExecutor;
    $executor->failWhen = fn (string $command): bool => (
        str_starts_with($command, 'mv ')
            && str_contains($command, '.candidate-')
    ) || (
        str_starts_with($command, 'rm -rf -- ')
            && str_contains($command, '.backup-')
            && str_contains($command, 'if [ -e')
    );

    $harness = Mockery::mock(HarnessManager::class);
    $harness->shouldReceive('resolveExecutor')->once()->andReturn($executor);
    app()->instance(HarnessManager::class, $harness);

    expect(fn () => app(ArtifactStaticService::class)->deploy($agent, $artifact))
        ->toThrow(RuntimeException::class, 'Simulated staging failure.');

    $cleanup = $executor->commands[array_key_last($executor->commands)];

    expect($cleanup)
        ->toStartWith("rm -rf -- '/srv/provision-artifacts/{$agent->slug}/{$artifact->id}/a1b2c3d4e5f60708.candidate-")
        ->not->toContain('.backup-');
});

test('static removal is scoped to the immutable published revision', function () {
    [$agent, $artifact] = staticArtifactFixture();
    $executor = new RecordingArtifactStaticExecutor;
    $harness = Mockery::mock(HarnessManager::class);
    $harness->shouldReceive('resolveExecutor')->once()->andReturn($executor);
    app()->instance(HarnessManager::class, $harness);

    app(ArtifactStaticService::class)->remove($agent, $artifact);

    expect($executor->commands)->toBe([
        "rm -rf -- '/srv/provision-artifacts/{$agent->slug}/{$artifact->id}/a1b2c3d4e5f60708'",
    ]);
});

test('static artifact removal deletes every staged revision for only that artifact', function () {
    [$agent, $artifact] = staticArtifactFixture();
    $executor = new RecordingArtifactStaticExecutor;
    $harness = Mockery::mock(HarnessManager::class);
    $harness->shouldReceive('resolveExecutor')->once()->andReturn($executor);
    app()->instance(HarnessManager::class, $harness);

    app(ArtifactStaticService::class)->removeArtifact($agent, $artifact);

    expect($executor->commands)->toBe([
        "rm -rf -- '/srv/provision-artifacts/{$agent->slug}/{$artifact->id}'",
    ]);
});

test('static stale revision cleanup preserves the active deployment', function () {
    [$agent, $artifact] = staticArtifactFixture();
    $executor = new RecordingArtifactStaticExecutor;
    $harness = Mockery::mock(HarnessManager::class);
    $harness->shouldReceive('resolveExecutor')->once()->andReturn($executor);
    app()->instance(HarnessManager::class, $harness);

    app(ArtifactStaticService::class)->removeStaleRevisions($agent, $artifact);

    expect($executor->commands)->toBe([
        "if [ -d '/srv/provision-artifacts/{$agent->slug}/{$artifact->id}' ]; then find '/srv/provision-artifacts/{$agent->slug}/{$artifact->id}' -mindepth 1 -maxdepth 1 -type d ! -name 'a1b2c3d4e5f60708' -exec rm -rf -- {} +; fi",
    ]);
});

test('unsafe static artifact identifiers are rejected before resolving an executor', function (string $field, mixed $value, string $message) {
    [$agent, $artifact] = staticArtifactFixture();

    if (in_array($field, ['slug', 'harness_agent_id'], true)) {
        $agent->{$field} = $value;
    } else {
        $artifact->{$field} = $value;
    }

    $harness = Mockery::mock(HarnessManager::class);
    $harness->shouldNotReceive('resolveExecutor');
    app()->instance(HarnessManager::class, $harness);

    expect(fn () => app(ArtifactStaticService::class)->deploy($agent, $artifact))
        ->toThrow(RuntimeException::class, $message);
})->with([
    'agent slug traversal' => ['slug', '../luna', 'Agent slug is invalid.'],
    'agent runtime traversal' => ['harness_agent_id', '../luna', 'Agent runtime id is invalid.'],
    'artifact path traversal' => ['path_slug', '../dashboard', 'Artifact path slug is invalid.'],
    'artifact id traversal' => ['id', '../artifact', 'Artifact id is invalid.'],
    'deployment key traversal' => ['deployment_key', '../revision', 'Artifact deployment key is invalid.'],
    'source directory traversal' => ['source_dir', '../private', 'Artifact source directory is invalid.'],
]);

test('unsafe agent slugs are rejected before broad static cleanup', function () {
    [$agent] = staticArtifactFixture();
    $agent->slug = '../other-agent';

    $harness = Mockery::mock(HarnessManager::class);
    $harness->shouldNotReceive('resolveExecutor');
    app()->instance(HarnessManager::class, $harness);

    expect(fn () => app(ArtifactStaticService::class)->removeAgent($agent))
        ->toThrow(RuntimeException::class, 'Agent slug is invalid.');
});
