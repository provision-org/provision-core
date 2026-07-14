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

test('Bedrock tier keeps every model — primary, fallback, heartbeat — inside the customer AWS account', function () {
    expect(ModelTier::Bedrock->label())->toBe('Bedrock (your AWS)')
        ->and(ModelTier::Bedrock->description())->toBe('Claude models running in your own AWS account via Amazon Bedrock. Model traffic never leaves your cloud.')
        ->and(ModelTier::Bedrock->estimatedMonthlyCost())->toBe('Billed to your AWS account')
        ->and(ModelTier::Bedrock->primaryModel())->toBe('bedrock-claude-sonnet-4-6')
        ->and(ModelTier::Bedrock->fallbackModels())->toBe(['bedrock-claude-haiku-4-5'])
        ->and(ModelTier::Bedrock->heartbeatModel())->toBe('bedrock-claude-haiku-4-5')
        ->and(ModelTier::Bedrock->authProvider())->toBe('bedrock');
});

test('non-Bedrock tiers keep the existing heartbeat model', function () {
    expect(ModelTier::Efficient->heartbeatModel())->toBe('claude-haiku-4-5')
        ->and(ModelTier::Powerful->heartbeatModel())->toBe('claude-haiku-4-5')
        ->and(ModelTier::Subscription->heartbeatModel())->toBe('claude-haiku-4-5');
});
