<?php

use App\Enums\ModelTier;

test('Powerful tier primary model is Opus (matches the ~$50/mo UX copy) — issue #31', function () {
    expect(ModelTier::Powerful->primaryModel())->toBe('claude-opus-4-6');
});

test('Powerful tier fallback chain is Sonnet only (no duplicate) — issue #31', function () {
    expect(ModelTier::Powerful->fallbackModels())->toBe(['claude-sonnet-4-6']);
});

test('Efficient tier remains Haiku primary with Sonnet fallback', function () {
    expect(ModelTier::Efficient->primaryModel())->toBe('claude-haiku-4-5')
        ->and(ModelTier::Efficient->fallbackModels())->toBe(['claude-sonnet-4-6']);
});

test('Subscription tier uses GPT-5.5 with no fallbacks', function () {
    expect(ModelTier::Subscription->primaryModel())->toBe('gpt-5.5')
        ->and(ModelTier::Subscription->fallbackModels())->toBe([])
        ->and(ModelTier::Subscription->authProvider())->toBe('chatgpt');
});
