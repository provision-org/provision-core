<?php

use App\Contracts\CommandExecutor;
use App\Enums\LlmProvider;
use App\Jobs\RestartGatewayJob;
use App\Jobs\UpdateEnvOnServerJob;
use App\Models\Agent;
use App\Models\Server;
use App\Models\Team;
use App\Models\TeamApiKey;
use App\Models\TeamEnvVar;
use App\Services\HarnessManager;
use App\Services\OpenClawDefaultsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

function mockExecutorWithConfigUpdate(MockInterface $executor, ?string &$writtenConfig = null): void
{
    $existingConfig = json_encode(['agents' => ['defaults' => ['sandbox' => ['mode' => 'off']]]]);
    $executor->shouldReceive('readFile')
        ->with('/root/.openclaw/openclaw.json')
        ->andReturn($existingConfig);
    $executor->shouldReceive('writeFile')
        ->with('/root/.openclaw/openclaw.json', Mockery::on(function ($content) use (&$writtenConfig) {
            $writtenConfig = $content;

            return json_decode($content) !== null;
        }))
        ->once();
}

function mockHarnessManager(MockInterface $executor): HarnessManager
{
    $harnessManager = Mockery::mock(HarnessManager::class);
    $harnessManager->shouldReceive('resolveExecutor')->andReturn($executor);

    return $harnessManager;
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

    $executor = Mockery::mock(CommandExecutor::class);
    $executor->shouldReceive('writeFile')
        ->once()
        ->with('/root/.openclaw/.env', Mockery::on(function ($content) use (&$writtenContent) {
            $writtenContent = $content;

            return true;
        }));
    mockExecutorWithConfigUpdate($executor, $writtenConfigJson);

    (new UpdateEnvOnServerJob($server))->handle(mockHarnessManager($executor), new OpenClawDefaultsService);

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

    $executor = Mockery::mock(CommandExecutor::class);
    $executor->shouldReceive('writeFile')
        ->once()
        ->with('/root/.openclaw/.env', Mockery::on(function ($content) use (&$writtenContent) {
            $writtenContent = $content;

            return true;
        }));
    mockExecutorWithConfigUpdate($executor, $writtenConfigJson);

    (new UpdateEnvOnServerJob($server))->handle(mockHarnessManager($executor), new OpenClawDefaultsService);

    expect($writtenContent)->not->toContain('OPENAI_API_KEY');

    // Verify inactive keys are also not in openclaw.json env section
    $config = json_decode($writtenConfigJson, true);
    expect($config)->not->toHaveKey('env');
});

test('it pushes AWS_REGION but never the AWS key or secret for BYO-AWS teams', function () {
    Bus::fake([RestartGatewayJob::class]);

    $team = Team::factory()->aws()->create();
    $server = Server::factory()->running()->aws()->create(['team_id' => $team->id]);

    TeamApiKey::factory()->awsCloud()->create([
        'team_id' => $team->id,
        'api_key' => json_encode([
            'key_id' => 'AKIALEAKCANARYVALUE',
            'secret' => 'leak-canary-secret',
            'region' => 'eu-central-1',
        ]),
    ]);

    Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'auth_provider' => 'bedrock',
        'model_primary' => 'bedrock-claude-sonnet-4-6',
    ]);

    $writtenContent = null;
    $writtenConfigJson = null;

    $executor = Mockery::mock(CommandExecutor::class);
    $executor->shouldReceive('writeFile')
        ->once()
        ->with('/root/.openclaw/.env', Mockery::on(function ($content) use (&$writtenContent) {
            $writtenContent = $content;

            return true;
        }));
    mockExecutorWithConfigUpdate($executor, $writtenConfigJson);

    (new UpdateEnvOnServerJob($server))->handle(mockHarnessManager($executor), new OpenClawDefaultsService);

    // The bedrock plugin needs the region; auth is the EC2 instance profile,
    // so the IAM key/secret must never land on the box.
    expect($writtenContent)->toContain('AWS_REGION=eu-central-1')
        ->and($writtenContent)->not->toContain('AKIALEAKCANARYVALUE')
        ->and($writtenContent)->not->toContain('leak-canary-secret');

    $config = json_decode($writtenConfigJson, true);
    expect($config['env']['AWS_REGION'])->toBe('eu-central-1')
        ->and($config['plugins']['entries']['amazon-bedrock']['config']['discovery'])->toBe([
            'enabled' => true,
            'region' => 'eu-central-1',
        ])
        ->and(json_encode($config))->not->toContain('AKIALEAKCANARYVALUE')
        ->and(json_encode($config))->not->toContain('leak-canary-secret');

    // All-bedrock server: defaults route heartbeat + subagents in-cloud too
    expect($config['agents']['defaults']['heartbeat']['model'])->toBe('amazon-bedrock/us.anthropic.claude-haiku-4-5-v1:0')
        ->and($config['agents']['defaults']['subagents']['model'])->toBe('amazon-bedrock/us.anthropic.claude-haiku-4-5-v1:0')
        ->and($config['agents']['defaults']['memorySearch']['provider'])->toBe('bedrock');
});
