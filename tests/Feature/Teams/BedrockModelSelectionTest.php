<?php

use App\Models\User;
use App\Services\Aws\AwsCredentials;
use App\Services\Aws\BedrockCatalogService;
use App\Services\AwsService;
use App\Services\CloudServiceFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

/**
 * Swap the factory so both the STS credential check and the Bedrock catalog
 * calls are doubled — nothing hits real AWS.
 */
function mockBedrockFactory(BedrockCatalogService $catalog, bool $credsOk = true): void
{
    $aws = Mockery::mock(AwsService::class);
    $aws->shouldReceive('verifyCredentials')->andReturn([
        'account_id' => '123456789012',
        'arn' => 'arn:aws:iam::123456789012:user/provision',
    ]);

    test()->mock(CloudServiceFactory::class, function ($mock) use ($aws, $catalog): void {
        $mock->shouldReceive('makeAwsForCredentials')->andReturn($aws);
        $mock->shouldReceive('makeBedrockCatalogForCredentials')->andReturn($catalog);
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

test('a bare raw model id is normalised to the bedrock: form on store', function () {
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
        'aws_bedrock_model' => 'deepseek.v3.2',
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

test('bedrock-models endpoint returns the catalog and auto-detected default', function () {
    $catalog = Mockery::mock(BedrockCatalogService::class);
    $catalog->shouldReceive('listModels')->once()->andReturn([
        ['id' => 'openai.gpt-oss-120b-1:0', 'label' => 'GPT-OSS 120B', 'provider' => 'OpenAI', 'requiresUseCaseForm' => false],
    ]);
    $catalog->shouldReceive('resolveBestDefaultModel')->once()->andReturn('openai.gpt-oss-120b-1:0');
    mockBedrockFactory($catalog);

    $user = User::factory()->withCompletedProfile()->byoCloud()->create();

    $this->actingAs($user)
        ->postJson(route('teams.bedrock-models'), [
            'aws_key_id' => 'AKIAEXAMPLE000000000',
            'aws_secret' => 'super-secret',
            'aws_region' => 'us-east-1',
        ])
        ->assertOk()
        ->assertJsonPath('default', 'bedrock:openai.gpt-oss-120b-1:0')
        ->assertJsonPath('models.0.id', 'openai.gpt-oss-120b-1:0');
});

test('verify-bedrock-model endpoint surfaces the use-case-form gate', function () {
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
            'aws_region' => 'us-east-1',
            'model_id' => 'bedrock:us.anthropic.claude-sonnet-4-6',
        ])
        ->assertStatus(422)
        ->assertJsonPath('useCaseForm', true);
});
