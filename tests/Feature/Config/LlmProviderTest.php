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

test('bedrock openclawModel maps to amazon-bedrock inference profiles — never via OpenRouter', function () {
    // Real OpenClaw contract: "amazon-bedrock/" prefix + "us." regional
    // inference profile. AWS uses no uniform suffix scheme — verified against
    // bedrock:ListInferenceProfiles: sonnet 4.6 has none, opus 4.6 ends "-v1",
    // haiku 4.5 carries a dated "-20251001-v1:0". Ids are mapped, not derived.
    expect(LlmProvider::Bedrock->openclawModel('bedrock-claude-opus-4-6'))
        ->toBe('amazon-bedrock/us.anthropic.claude-opus-4-6-v1')
        ->and(LlmProvider::Bedrock->openclawModel('bedrock-claude-sonnet-4-6'))
        ->toBe('amazon-bedrock/us.anthropic.claude-sonnet-4-6')
        ->and(LlmProvider::Bedrock->openclawModel('bedrock-claude-haiku-4-5'))
        ->toBe('amazon-bedrock/us.anthropic.claude-haiku-4-5-20251001-v1:0');

    foreach (LlmProvider::Bedrock->models() as $modelId) {
        expect(LlmProvider::Bedrock->openclawModel($modelId))->not->toContain('openrouter');
    }
});

test('customer-selected bedrock: ids pass straight through to the amazon-bedrock provider', function () {
    // Any raw AWS model id the account exposes is stored as "bedrock:<raw>" and
    // resolves to "amazon-bedrock/<raw>" with no remapping — no fixed enum entry.
    expect(LlmProvider::forModel('bedrock:openai.gpt-oss-120b-1:0'))
        ->toBe(LlmProvider::Bedrock)
        ->and(LlmProvider::Bedrock->openclawModel('bedrock:openai.gpt-oss-120b-1:0'))
        ->toBe('amazon-bedrock/openai.gpt-oss-120b-1:0')
        ->and(LlmProvider::Bedrock->openclawModel('bedrock:deepseek.v3.2'))
        ->toBe('amazon-bedrock/deepseek.v3.2')
        // The prefixed Claude form resolves to the same profile as the legacy enum.
        ->and(LlmProvider::Bedrock->openclawModel('bedrock:us.anthropic.claude-sonnet-4-6'))
        ->toBe('amazon-bedrock/us.anthropic.claude-sonnet-4-6');
});

test('mantle: ids route to the amazon-bedrock-mantle provider, distinct from classic bedrock:', function () {
    // Bedrock Mantle models are stored as "mantle:<raw>" and resolve to the
    // bundled amazon-bedrock-mantle provider (native Anthropic/OpenAI APIs),
    // NOT the classic amazon-bedrock ConverseStream provider.
    expect(LlmProvider::forModel('mantle:anthropic.claude-sonnet-5'))
        ->toBe(LlmProvider::Bedrock)
        ->and(LlmProvider::Bedrock->openclawModel('mantle:anthropic.claude-sonnet-5'))
        ->toBe('amazon-bedrock-mantle/anthropic.claude-sonnet-5')
        ->and(LlmProvider::Bedrock->openclawModel('mantle:openai.gpt-oss-120b'))
        ->toBe('amazon-bedrock-mantle/openai.gpt-oss-120b')
        // classic bedrock: is unaffected
        ->and(LlmProvider::Bedrock->openclawModel('bedrock:openai.gpt-oss-120b-1:0'))
        ->toBe('amazon-bedrock/openai.gpt-oss-120b-1:0');
});
