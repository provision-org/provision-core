<?php

use App\Enums\LlmProvider;

test('all enum values exist', function () {
    expect(LlmProvider::cases())->toHaveCount(3)
        ->and(LlmProvider::Anthropic->value)->toBe('anthropic')
        ->and(LlmProvider::OpenAi->value)->toBe('openai')
        ->and(LlmProvider::OpenRouter->value)->toBe('open_router');
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
