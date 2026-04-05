<?php

use App\Enums\LlmProvider;
use App\Jobs\RestartGatewayJob;
use App\Jobs\UpdateEnvOnServerJob;
use App\Models\Server;
use App\Models\Team;
use App\Models\TeamApiKey;
use App\Models\TeamEnvVar;
use App\Services\OpenClawDefaultsService;
use App\Services\SshService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

function mockSshWithConfigUpdate(SshService $sshService, ?string &$writtenConfig = null): void
{
    $existingConfig = json_encode(['agents' => ['defaults' => ['sandbox' => ['mode' => 'off']]]]);
    $sshService->shouldReceive('readFile')
        ->with('/root/.openclaw/openclaw.json')
        ->andReturn($existingConfig);
    $sshService->shouldReceive('writeFile')
        ->with('/root/.openclaw/openclaw.json', Mockery::on(function ($content) use (&$writtenConfig) {
            $writtenConfig = $content;

            return json_decode($content) !== null;
        }))
        ->once();
}

test('it generates env file content from api keys and env vars', function () {
    Bus::fake([RestartGatewayJob::class]);

    $team = Team::factory()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);

    TeamApiKey::factory()->create([
        'team_id' => $team->id,
        'provider' => LlmProvider::Anthropic,
        'api_key' => 'sk-ant-test-key',
        'is_active' => true,
    ]);

    TeamEnvVar::factory()->create([
        'team_id' => $team->id,
        'key' => 'CUSTOM_VAR',
        'value' => 'custom-value',
    ]);

    $writtenContent = null;
    $writtenConfigJson = null;

    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')->once()->andReturnSelf();
    $sshService->shouldReceive('writeFile')
        ->once()
        ->with('/root/.openclaw/.env', Mockery::on(function ($content) use (&$writtenContent) {
            $writtenContent = $content;

            return true;
        }));
    mockSshWithConfigUpdate($sshService, $writtenConfigJson);
    $sshService->shouldReceive('disconnect')->once();

    (new UpdateEnvOnServerJob($server))->handle($sshService, new OpenClawDefaultsService);

    expect($writtenContent)->toContain('ANTHROPIC_API_KEY=sk-ant-test-key')
        ->and($writtenContent)->toContain('CUSTOM_VAR=custom-value');

    // Verify API keys are also in openclaw.json env section
    $config = json_decode($writtenConfigJson, true);
    expect($config['env']['ANTHROPIC_API_KEY'])->toBe('sk-ant-test-key');

    Bus::assertDispatched(RestartGatewayJob::class);
});

test('it skips inactive api keys', function () {
    Bus::fake([RestartGatewayJob::class]);

    $team = Team::factory()->create();
    $server = Server::factory()->running()->create(['team_id' => $team->id]);

    TeamApiKey::factory()->inactive()->create([
        'team_id' => $team->id,
        'provider' => LlmProvider::OpenAi,
        'api_key' => 'sk-inactive-key',
    ]);

    $writtenContent = null;
    $writtenConfigJson = null;

    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')->once()->andReturnSelf();
    $sshService->shouldReceive('writeFile')
        ->once()
        ->with('/root/.openclaw/.env', Mockery::on(function ($content) use (&$writtenContent) {
            $writtenContent = $content;

            return true;
        }));
    mockSshWithConfigUpdate($sshService, $writtenConfigJson);
    $sshService->shouldReceive('disconnect')->once();

    (new UpdateEnvOnServerJob($server))->handle($sshService, new OpenClawDefaultsService);

    expect($writtenContent)->not->toContain('OPENAI_API_KEY');

    // Verify inactive keys are also not in openclaw.json env section
    $config = json_decode($writtenConfigJson, true);
    expect($config)->not->toHaveKey('env');
});
