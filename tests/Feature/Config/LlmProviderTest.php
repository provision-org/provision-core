<?php

use App\Enums\LlmProvider;

test('all enum values exist', function () {
    expect(LlmProvider::cases())->toHaveCount(5)
        ->and(LlmProvider::Anthropic->value)->toBe('anthropic')
        ->and(LlmProvider::OpenAi->value)->toBe('openai')
        ->and(LlmProvider::OpenRouter->value)->toBe('open_router')
        ->and(LlmProvider::OpenAiCodex->value)->toBe('openai_codex')
        ->and(LlmProvider::Bedrock->value)->toBe('bedrock');
});

test('envKeyName returns correct env var names', function () {
    expect(LlmProvider::Anthropic->envKeyName())->toBe('ANTHROPIC_API_KEY')
        ->and(LlmProvider::OpenAi->envKeyName())->toBe('OPENAI_API_KEY')
        ->and(LlmProvider::OpenRouter->envKeyName())->toBe('OPENROUTER_API_KEY');
});

test('label returns human-readable labels', function () {
    expect(LlmProvider::Anthropic->label())->toBe('Anthropic')
        ->and(LlmProvider::OpenAi->label())->toBe('OpenAI')
        ->and(LlmProvider::OpenRouter->label())->toBe('OpenRouter');
});

test('models returns non-empty arrays for each provider', function () {
    foreach (LlmProvider::cases() as $provider) {
        expect($provider->models())->toBeArray()->not->toBeEmpty();
    }
});

test('bedrock provider exposes the Bedrock Claude models', function () {
    expect(LlmProvider::Bedrock->label())->toBe('AWS Bedrock')
        ->and(LlmProvider::Bedrock->models())->toBe([
            'bedrock-claude-opus-4-6',
            'bedrock-claude-sonnet-4-6',
            'bedrock-claude-haiku-4-5',
        ]);
});

test('forModel resolves bedrock model ids to the Bedrock provider', function () {
    expect(LlmProvider::forModel('bedrock-claude-sonnet-4-6'))->toBe(LlmProvider::Bedrock)
        ->and(LlmProvider::forModel('bedrock-claude-haiku-4-5'))->toBe(LlmProvider::Bedrock)
        // Non-prefixed Claude ids must keep resolving to Anthropic
        ->and(LlmProvider::forModel('claude-sonnet-4-6'))->toBe(LlmProvider::Anthropic);
});

test('bedrock openclawModel routes directly to Bedrock with dotted versions — never via OpenRouter', function () {
    expect(LlmProvider::Bedrock->openclawModel('bedrock-claude-opus-4-6'))
        ->toBe('bedrock/anthropic.claude-opus-4.6')
        ->and(LlmProvider::Bedrock->openclawModel('bedrock-claude-sonnet-4-6'))
        ->toBe('bedrock/anthropic.claude-sonnet-4.6')
        ->and(LlmProvider::Bedrock->openclawModel('bedrock-claude-haiku-4-5'))
        ->toBe('bedrock/anthropic.claude-haiku-4.5');

    foreach (LlmProvider::Bedrock->models() as $modelId) {
        expect(LlmProvider::Bedrock->openclawModel($modelId))->not->toContain('openrouter');
    }
});
