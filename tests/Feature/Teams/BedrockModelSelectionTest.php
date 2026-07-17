<?php

use App\Models\User;
use App\Services\Aws\AwsCredentials;
use App\Services\Aws\BedrockCatalogService;
use App\Services\Aws\MantleCatalogService;
use App\Services\AwsService;
use App\Services\CloudServiceFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

function mockStsAws(): AwsService
{
    $aws = Mockery::mock(AwsService::class);
    $aws->shouldReceive('verifyCredentials')->andReturn([
        'account_id' => '123456789012',
        'arn' => 'arn:aws:iam::123456789012:user/provision',
    ]);

    return $aws;
}

/**
 * Swap the factory so the STS credential check and the CLASSIC ConverseStream
 * catalog are doubled — for non-Mantle regions. Nothing hits real AWS.
 */
function mockBedrockFactory(BedrockCatalogService $catalog): void
{
    $aws = mockStsAws();
    test()->mock(CloudServiceFactory::class, function ($mock) use ($aws, $catalog): void {
        $mock->shouldReceive('makeAwsForCredentials')->andReturn($aws);
        $mock->shouldReceive('makeBedrockCatalogForCredentials')->andReturn($catalog);
    });
}

/**
 * Swap the factory so the STS check and the MANTLE catalog are doubled — the
 * path a Mantle-supported region (e.g. us-east-1) takes.
 */
function mockMantleFactory(MantleCatalogService $catalog): void
{
    $aws = mockStsAws();
    test()->mock(CloudServiceFactory::class, function ($mock) use ($aws, $catalog): void {
        $mock->shouldReceive('makeAwsForCredentials')->andReturn($aws);
        $mock->shouldReceive('makeMantleCatalogForCredentials')->andReturn($catalog);
    });
}

test('team creation stores the chosen default bedrock model on the cloud key', function () {
    Bus::fake();
    config()->set('cloud.provider_selection_enabled', true);

    $catalog = Mockery::mock(BedrockCatalogService::class);
    mockBedrockFactory($catalog);

    $user = User::factory()->withCompletedProfile()->byoCloud()->create();

    $this->actingAs($user)->post(route('teams.store'), [
        'name' => 'AWS Team',
        'harness_type' => 'openclaw',
        'cloud_provider' => 'aws',
        'aws_key_id' => 'AKIAEXAMPLE000000000',
        'aws_secret' => 'super-secret',
        'aws_region' => 'us-east-1',
        'aws_instance_profile' => 'provision-bedrock',
        'aws_bedrock_model' => 'bedrock:openai.gpt-oss-120b-1:0',
    ]);

    $team = $user->fresh()->currentTeam;
    $credentials = json_decode($team->cloudApiKeys()->where('provider', 'aws')->first()->api_key, true);

    expect($credentials['default_bedrock_model'])->toBe('bedrock:openai.gpt-oss-120b-1:0')
        ->and(AwsCredentials::defaultBedrockModelForTeam($team))->toBe('bedrock:openai.gpt-oss-120b-1:0');
});

test('a bare raw model id is region-prefixed on store (mantle: in a Mantle region)', function () {
    Bus::fake();
    config()->set('cloud.provider_selection_enabled', true);

    $catalog = Mockery::mock(BedrockCatalogService::class);
    mockBedrockFactory($catalog);

    $user = User::factory()->withCompletedProfile()->byoCloud()->create();

    $this->actingAs($user)->post(route('teams.store'), [
        'name' => 'AWS Team',
        'harness_type' => 'openclaw',
        'cloud_provider' => 'aws',
        'aws_key_id' => 'AKIAEXAMPLE000000000',
        'aws_secret' => 'super-secret',
        'aws_region' => 'us-east-1',
        'aws_instance_profile' => 'provision-bedrock',
        'aws_bedrock_model' => 'deepseek.v3.2',
    ]);

    $team = $user->fresh()->currentTeam;
    $credentials = json_decode($team->cloudApiKeys()->where('provider', 'aws')->first()->api_key, true);

    // us-east-1 supports Mantle, so a bare id is stored under the mantle: prefix.
    expect($credentials['default_bedrock_model'])->toBe('mantle:deepseek.v3.2');
});

test('an explicit bedrock: prefix is honoured on store even in a Mantle region', function () {
    Bus::fake();
    config()->set('cloud.provider_selection_enabled', true);

    $catalog = Mockery::mock(BedrockCatalogService::class);
    mockBedrockFactory($catalog);

    $user = User::factory()->withCompletedProfile()->byoCloud()->create();

    $this->actingAs($user)->post(route('teams.store'), [
        'name' => 'AWS Team',
        'harness_type' => 'openclaw',
        'cloud_provider' => 'aws',
        'aws_key_id' => 'AKIAEXAMPLE000000000',
        'aws_secret' => 'super-secret',
        'aws_region' => 'us-east-1',
        'aws_instance_profile' => 'provision-bedrock',
        'aws_bedrock_model' => 'bedrock:deepseek.v3.2',
    ]);

    $team = $user->fresh()->currentTeam;
    $credentials = json_decode($team->cloudApiKeys()->where('provider', 'aws')->first()->api_key, true);

    expect($credentials['default_bedrock_model'])->toBe('bedrock:deepseek.v3.2');
});

test('bedrock-models endpoint is forbidden without byo_cloud_enabled', function () {
    $user = User::factory()->withCompletedProfile()->create();

    $this->actingAs($user)
        ->postJson(route('teams.bedrock-models'), [
            'aws_key_id' => 'AKIAEXAMPLE000000000',
            'aws_secret' => 'super-secret',
            'aws_region' => 'us-east-1',
        ])
        ->assertForbidden();
});

test('bedrock-models endpoint returns the Mantle catalog with ZDR flags and default (us-east-1)', function () {
    $catalog = Mockery::mock(MantleCatalogService::class);
    $catalog->shouldReceive('listModels')->once()->andReturn([
        ['id' => 'anthropic.claude-sonnet-5', 'label' => 'Claude Sonnet 5', 'provider' => 'Anthropic', 'requiresUseCaseForm' => false, 'zeroRetention' => true],
    ]);
    $catalog->shouldReceive('resolveBestDefaultModel')->once()->andReturn('anthropic.claude-sonnet-5');
    mockMantleFactory($catalog);

    $user = User::factory()->withCompletedProfile()->byoCloud()->create();

    $this->actingAs($user)
        ->postJson(route('teams.bedrock-models'), [
            'aws_key_id' => 'AKIAEXAMPLE000000000',
            'aws_secret' => 'super-secret',
            'aws_region' => 'us-east-1',
        ])
        ->assertOk()
        ->assertJsonPath('mode', 'mantle')
        ->assertJsonPath('default', 'mantle:anthropic.claude-sonnet-5')
        ->assertJsonPath('models.0.id', 'anthropic.claude-sonnet-5')
        ->assertJsonPath('models.0.zeroRetention', true);
});

test('verify-bedrock-model surfaces the classic use-case-form gate in a non-Mantle region', function () {
    // ca-central-1 is not a Mantle region, so the classic ConverseStream
    // catalog runs — the only path that produces the Anthropic use-case gate.
    $catalog = Mockery::mock(BedrockCatalogService::class);
    $catalog->shouldReceive('verifyModel')
        ->once()
        ->with('us.anthropic.claude-sonnet-4-6')
        ->andReturn(['ok' => false, 'error' => 'use case details not submitted', 'useCaseForm' => true]);
    mockBedrockFactory($catalog);

    $user = User::factory()->withCompletedProfile()->byoCloud()->create();

    $this->actingAs($user)
        ->postJson(route('teams.verify-bedrock-model'), [
            'aws_key_id' => 'AKIAEXAMPLE000000000',
            'aws_secret' => 'super-secret',
            'aws_region' => 'ca-central-1',
            'model_id' => 'bedrock:us.anthropic.claude-sonnet-4-6',
        ])
        ->assertStatus(422)
        ->assertJsonPath('useCaseForm', true);
});
