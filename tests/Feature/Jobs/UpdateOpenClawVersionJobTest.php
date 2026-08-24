<?php

use App\Contracts\CommandExecutor;
use App\Jobs\UpdateOpenClawVersionJob;
use App\Models\Server;
use App\Models\Team;
use App\Services\HarnessManager;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function mockUpdateExecutor(array $responses, array &$commands): CommandExecutor
{
    $executor = Mockery::mock(CommandExecutor::class);
    $executor->shouldReceive('exec')->andReturnUsing(function (string $command) use ($responses, &$commands) {
        $commands[] = $command;

        foreach ($responses as $needle => $response) {
            if (str_contains($command, $needle)) {
                return $response;
            }
        }

        return '';
    });

    return $executor;
}

function updateJobHarness(CommandExecutor $executor): HarnessManager
{
    $manager = Mockery::mock(HarnessManager::class);
    $manager->shouldReceive('resolveExecutor')->andReturn($executor);

    return $manager;
}

test('it records the version and skips the updater when already on the pin', function () {
    config(['provision.openclaw_version' => '2026.7.1']);

    $team = Team::factory()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id, 'openclaw_version' => null]);

    $commands = [];
    $executor = mockUpdateExecutor(['--version' => "2026.7.1\n"], $commands);

    (new UpdateOpenClawVersionJob($server))->handle(updateJobHarness($executor));

    expect(implode("\n", $commands))->not->toContain('openclaw update')
        ->and($server->fresh()->openclaw_version)->toBe('2026.7.1');
});

test('it converges a drifted server through the official updater', function () {
    config(['provision.openclaw_version' => '2026.7.1']);

    $team = Team::factory()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id, 'openclaw_version' => '2026.5.3']);

    $commands = [];
    $versions = ['2026.5.3', '2026.7.1'];
    $executor = Mockery::mock(CommandExecutor::class);
    $executor->shouldReceive('exec')->andReturnUsing(function (string $command) use (&$commands, &$versions) {
        $commands[] = $command;

        if (str_contains($command, '--version')) {
            return array_shift($versions)."\n";
        }

        return '';
    });

    (new UpdateOpenClawVersionJob($server))->handle(updateJobHarness($executor));

    expect(implode("\n", $commands))
        ->toContain("openclaw update --tag '2026.7.1' --yes --json")
        // The raw npm swap requires stopping the gateway first — it must not
        // reappear as the update mechanism here.
        ->not->toContain('npm install -g');

    expect($server->fresh()->openclaw_version)->toBe('2026.7.1');
});

test('it throws when the updater does not converge on the pin', function () {
    config(['provision.openclaw_version' => '2026.7.1']);

    $team = Team::factory()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id, 'openclaw_version' => '2026.5.3']);

    $commands = [];
    $executor = mockUpdateExecutor(['--version' => "2026.5.3\n"], $commands);

    (new UpdateOpenClawVersionJob($server))->handle(updateJobHarness($executor));
})->throws(RuntimeException::class, 'did not converge');
