<?php

use App\Services\Aws\AwsCredentials;
use App\Services\Aws\MantleCatalogService;
use App\Services\Aws\MantleTokenGenerator;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;

function mantleCreds(string $region = 'us-east-1'): AwsCredentials
{
    return new AwsCredentials(
        keyId: 'AKIAEXAMPLE000000000',
        secret: 'super-secret-value',
        region: $region,
    );
}

function makeMantleCatalog(): MantleCatalogService
{
    return new MantleCatalogService(mantleCreds(), new MantleTokenGenerator, app(HttpFactory::class));
}

test('token generator produces a decodable presigned CallWithBearerToken bearer key', function () {
    $token = (new MantleTokenGenerator)->generate(mantleCreds());

    expect($token)->toStartWith('bedrock-api-key-');

    $decoded = base64_decode(substr($token, strlen('bedrock-api-key-')));

    expect($decoded)
        ->toContain('bedrock.amazonaws.com/?Action=CallWithBearerToken')
        ->toContain('X-Amz-Algorithm=AWS4-HMAC-SHA256')
        ->toContain('X-Amz-Credential=')
        ->toContain('us-east-1%2Fbedrock')
        ->toContain('X-Amz-Signature=')
        ->toEndWith('&Version=1');
});

test('listModels returns available models with provider + zero-retention flag, skipping unavailable', function () {
    Http::fake([
        'bedrock-mantle.us-east-1.api.aws/v1/models' => Http::response(['data' => [
            ['id' => 'anthropic.claude-sonnet-5', 'status' => 'available', 'data_retention' => ['allowed_modes' => ['none', 'default']]],
            ['id' => 'openai.gpt-5.4', 'status' => 'available', 'data_retention' => ['allowed_modes' => ['provider_data_share', 'default']]],
            ['id' => 'anthropic.claude-fable-5', 'status' => 'unavailable', 'data_retention' => ['allowed_modes' => ['none']]],
        ]]),
    ]);

    $models = makeMantleCatalog()->listModels();

    expect($models)->toHaveCount(2)
        // sorted by provider then label: Anthropic before OpenAI
        ->and($models[0]['id'])->toBe('anthropic.claude-sonnet-5')
        ->and($models[0]['label'])->toBe('Claude Sonnet 5')
        ->and($models[0]['provider'])->toBe('Anthropic')
        ->and($models[0]['zeroRetention'])->toBeTrue()
        ->and($models[0]['requiresUseCaseForm'])->toBeFalse()
        // gpt-5.4 lists as available but lacks a "none" retention mode
        ->and($models[1]['id'])->toBe('openai.gpt-5.4')
        ->and($models[1]['zeroRetention'])->toBeFalse();
});

test('verifyModel routes anthropic ids to the /anthropic path and others to /v1/chat/completions', function () {
    Http::fake([
        'bedrock-mantle.us-east-1.api.aws/anthropic/v1/messages' => Http::response(['content' => [['text' => 'OK']]]),
        'bedrock-mantle.us-east-1.api.aws/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'OK']]]]),
    ]);

    $catalog = makeMantleCatalog();

    expect($catalog->verifyModel('anthropic.claude-sonnet-5')['ok'])->toBeTrue()
        ->and($catalog->verifyModel('openai.gpt-oss-120b')['ok'])->toBeTrue();

    Http::assertSent(fn ($req) => str_contains($req->url(), '/anthropic/v1/messages')
        && $req['model'] === 'anthropic.claude-sonnet-5');
    Http::assertSent(fn ($req) => str_contains($req->url(), '/v1/chat/completions')
        && $req['model'] === 'openai.gpt-oss-120b');
});

test('verifyModel surfaces a Mantle validation error as ok=false', function () {
    Http::fake([
        'bedrock-mantle.us-east-1.api.aws/v1/chat/completions' => Http::response([
            'error' => ['message' => "The model 'openai.gpt-5.6-luna' does not support the '/v1/chat/completions' API"],
        ], 400),
    ]);

    $result = makeMantleCatalog()->verifyModel('openai.gpt-5.6-luna');

    expect($result['ok'])->toBeFalse()
        ->and($result['error'])->toContain('does not support');
});

test('resolveBestDefaultModel picks the first preference that lists and verifies', function () {
    Http::fake([
        'bedrock-mantle.us-east-1.api.aws/v1/models' => Http::response(['data' => [
            ['id' => 'anthropic.claude-sonnet-5', 'status' => 'available', 'data_retention' => ['allowed_modes' => ['none']]],
            ['id' => 'openai.gpt-oss-120b', 'status' => 'available', 'data_retention' => ['allowed_modes' => ['none']]],
        ]]),
        'bedrock-mantle.us-east-1.api.aws/anthropic/v1/messages' => Http::response(['content' => [['text' => 'OK']]]),
    ]);

    expect(makeMantleCatalog()->resolveBestDefaultModel())->toBe('anthropic.claude-sonnet-5');
});

test('supported regions gate the Mantle endpoint', function () {
    expect(MantleCatalogService::SUPPORTED_REGIONS)->toContain('us-east-1')
        ->and(MantleCatalogService::SUPPORTED_REGIONS)->not->toContain('us-west-1');
});
