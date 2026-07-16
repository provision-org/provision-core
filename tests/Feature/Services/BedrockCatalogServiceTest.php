<?php

use App\Services\Aws\AwsCredentials;
use App\Services\Aws\BedrockCatalogService;
use Aws\Bedrock\BedrockClient;
use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\Command;
use Aws\Exception\AwsException;
use Aws\Result;

function bedrockCreds(): AwsCredentials
{
    return new AwsCredentials('AKIATEST000000000000', 'secret', 'us-east-1');
}

function converseError(string $code, string $message): AwsException
{
    return new AwsException(
        'Error executing ConverseStream',
        new Command('ConverseStream'),
        ['message' => $message, 'code' => $code],
    );
}

it('lists only on-demand streaming foundation models plus system inference profiles', function () {
    $bedrock = Mockery::mock(BedrockClient::class);
    $bedrock->shouldReceive('listFoundationModels')->once()->andReturn(new Result([
        'modelSummaries' => [
            // Included: on-demand + streaming
            ['modelId' => 'openai.gpt-oss-120b-1:0', 'modelName' => 'GPT-OSS 120B', 'providerName' => 'OpenAI', 'inferenceTypesSupported' => ['ON_DEMAND'], 'responseStreamingSupported' => true],
            // Excluded: not on-demand (inference-profile only)
            ['modelId' => 'anthropic.claude-sonnet-4-6', 'modelName' => 'Claude Sonnet 4.6', 'providerName' => 'Anthropic', 'inferenceTypesSupported' => ['INFERENCE_PROFILE'], 'responseStreamingSupported' => true],
            // Excluded: streaming unsupported
            ['modelId' => 'foo.no-stream', 'modelName' => 'No Stream', 'providerName' => 'Foo', 'inferenceTypesSupported' => ['ON_DEMAND'], 'responseStreamingSupported' => false],
        ],
    ]));
    $bedrock->shouldReceive('listInferenceProfiles')->once()->andReturn(new Result([
        'inferenceProfileSummaries' => [
            ['inferenceProfileId' => 'us.anthropic.claude-sonnet-4-6', 'inferenceProfileName' => 'Claude Sonnet 4.6 (US)', 'type' => 'SYSTEM_DEFINED'],
            // Excluded: application (user-defined) profile
            ['inferenceProfileId' => 'my-custom-profile', 'inferenceProfileName' => 'Custom', 'type' => 'APPLICATION'],
        ],
    ]));

    $service = new BedrockCatalogService(bedrockCreds(), $bedrock);
    $models = $service->listModels();
    $ids = array_column($models, 'id');

    expect($ids)->toContain('openai.gpt-oss-120b-1:0')
        ->and($ids)->toContain('us.anthropic.claude-sonnet-4-6')
        ->and($ids)->not->toContain('anthropic.claude-sonnet-4-6')
        ->and($ids)->not->toContain('foo.no-stream')
        ->and($ids)->not->toContain('my-custom-profile');

    // Anthropic entries are flagged as needing the use-case form.
    $sonnet = collect($models)->firstWhere('id', 'us.anthropic.claude-sonnet-4-6');
    expect($sonnet['requiresUseCaseForm'])->toBeTrue();
    $oss = collect($models)->firstWhere('id', 'openai.gpt-oss-120b-1:0');
    expect($oss['requiresUseCaseForm'])->toBeFalse();
});

it('verifyModel returns ok when the stream drains without error', function () {
    $runtime = Mockery::mock(BedrockRuntimeClient::class);
    $runtime->shouldReceive('converseStream')->once()->andReturn(new Result(['stream' => []]));

    $service = new BedrockCatalogService(bedrockCreds(), null, $runtime);

    expect($service->verifyModel('us.anthropic.claude-sonnet-4-6'))->toBe(['ok' => true]);
});

it('verifyModel flags the Anthropic use-case-form gate', function () {
    $runtime = Mockery::mock(BedrockRuntimeClient::class);
    $runtime->shouldReceive('converseStream')->once()->andThrow(
        converseError('ResourceNotFoundException', 'Model use case details have not been submitted for this account.'),
    );

    $service = new BedrockCatalogService(bedrockCreds(), null, $runtime);
    $result = $service->verifyModel('us.anthropic.claude-sonnet-4-6');

    expect($result['ok'])->toBeFalse()
        ->and($result['useCaseForm'])->toBeTrue()
        ->and($result['error'])->toContain('use case');
});

it('resolveBestDefaultModel returns the strongest catalog model that passes the invoke check', function () {
    // Catalog has Sonnet (fails access) + Haiku + gpt-oss.
    $catalog = [
        ['id' => 'us.anthropic.claude-sonnet-4-6', 'label' => 'Sonnet', 'provider' => 'Anthropic', 'requiresUseCaseForm' => true],
        ['id' => 'us.anthropic.claude-haiku-4-5-20251001-v1:0', 'label' => 'Haiku', 'provider' => 'Anthropic', 'requiresUseCaseForm' => true],
        ['id' => 'openai.gpt-oss-120b-1:0', 'label' => 'GPT-OSS', 'provider' => 'OpenAI', 'requiresUseCaseForm' => false],
    ];

    $runtime = Mockery::mock(BedrockRuntimeClient::class);
    // Sonnet is tried first (top preference) and fails; Haiku is next and works.
    $runtime->shouldReceive('converseStream')
        ->with(Mockery::on(fn (array $p): bool => $p['modelId'] === 'us.anthropic.claude-sonnet-4-6'))
        ->once()
        ->andThrow(converseError('ResourceNotFoundException', 'use case details not submitted'));
    $runtime->shouldReceive('converseStream')
        ->with(Mockery::on(fn (array $p): bool => $p['modelId'] === 'us.anthropic.claude-haiku-4-5-20251001-v1:0'))
        ->once()
        ->andReturn(new Result(['stream' => []]));

    $service = new BedrockCatalogService(bedrockCreds(), null, $runtime);

    expect($service->resolveBestDefaultModel($catalog))
        ->toBe('us.anthropic.claude-haiku-4-5-20251001-v1:0');
});
