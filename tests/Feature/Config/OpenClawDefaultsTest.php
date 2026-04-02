<?php

use App\Enums\LlmProvider;
use App\Models\Server;
use App\Models\Team;
use App\Models\TeamApiKey;
use App\Services\OpenClawDefaultsService;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->service = new OpenClawDefaultsService;
    $this->team = Team::factory()->create();
    $this->server = Server::factory()->running()->create(['team_id' => $this->team->id]);
});

test('defaults include memory search with native OpenAI when team has OpenAI key', function () {
    TeamApiKey::factory()->create(['team_id' => $this->team->id, 'provider' => LlmProvider::OpenAi]);

    $defaults = $this->service->buildDefaults($this->server);

    expect($defaults['memorySearch']['enabled'])->toBeTrue()
        ->and($defaults['memorySearch']['provider'])->toBe('openai')
        ->and($defaults['memorySearch']['model'])->toBe('text-embedding-3-small')
        ->and($defaults['memorySearch'])->not->toHaveKey('remote');
});

test('defaults include memory search via OpenRouter when team has only OpenRouter key', function () {
    TeamApiKey::factory()->create(['team_id' => $this->team->id, 'provider' => LlmProvider::OpenRouter]);

    $defaults = $this->service->buildDefaults($this->server);

    expect($defaults['memorySearch']['enabled'])->toBeTrue()
        ->and($defaults['memorySearch']['provider'])->toBe('openai')
        ->and($defaults['memorySearch']['remote']['baseUrl'])->toBe('https://openrouter.ai/api/v1/');
});

test('defaults disable memory search when team has only Anthropic key', function () {
    TeamApiKey::factory()->create(['team_id' => $this->team->id, 'provider' => LlmProvider::Anthropic]);

    $defaults = $this->service->buildDefaults($this->server);

    expect($defaults['memorySearch']['enabled'])->toBeFalse();
});

test('defaults include compaction and context pruning always', function () {
    TeamApiKey::factory()->create(['team_id' => $this->team->id, 'provider' => LlmProvider::Anthropic]);

    $defaults = $this->service->buildDefaults($this->server);

    expect($defaults['compaction']['mode'])->toBe('safeguard')
        ->and($defaults['compaction']['memoryFlush']['enabled'])->toBeTrue()
        ->and($defaults['compaction']['memoryFlush']['softThresholdTokens'])->toBe(40000)
        ->and($defaults['contextPruning']['mode'])->toBe('cache-ttl')
        ->and($defaults['contextPruning']['ttl'])->toBe('6h')
        ->and($defaults['contextPruning']['keepLastAssistants'])->toBe(3);
});

test('defaults route heartbeat to cheap model when OpenAI available', function () {
    TeamApiKey::factory()->create(['team_id' => $this->team->id, 'provider' => LlmProvider::OpenAi]);

    $defaults = $this->service->buildDefaults($this->server);

    expect($defaults['heartbeat']['model'])->toBe('openai/gpt-5-nano')
        ->and($defaults['subagents']['model'])->toBe('openai/gpt-5-nano')
        ->and($defaults['subagents']['maxSpawnDepth'])->toBe(1)
        ->and($defaults['subagents']['maxChildrenPerAgent'])->toBe(5)
        ->and($defaults['subagents']['maxConcurrent'])->toBe(8)
        ->and($defaults['subagents']['runTimeoutSeconds'])->toBe(900);
});

test('defaults route heartbeat to OpenRouter when only OpenRouter', function () {
    TeamApiKey::factory()->create(['team_id' => $this->team->id, 'provider' => LlmProvider::OpenRouter]);

    $defaults = $this->service->buildDefaults($this->server);

    expect($defaults['heartbeat']['model'])->toBe('openrouter/z-ai/glm-4.7')
        ->and($defaults['subagents']['model'])->toBe('openrouter/z-ai/glm-4.7')
        ->and($defaults['subagents']['maxSpawnDepth'])->toBe(1)
        ->and($defaults['subagents']['runTimeoutSeconds'])->toBe(900);
});

test('defaults skip heartbeat routing when only Anthropic', function () {
    TeamApiKey::factory()->create(['team_id' => $this->team->id, 'provider' => LlmProvider::Anthropic]);

    $defaults = $this->service->buildDefaults($this->server);

    expect($defaults)->not->toHaveKey('heartbeat')
        ->and($defaults)->not->toHaveKey('subagents');
});

test('defaults include prompt caching when Anthropic available', function () {
    TeamApiKey::factory()->create(['team_id' => $this->team->id, 'provider' => LlmProvider::Anthropic]);

    $defaults = $this->service->buildDefaults($this->server);

    expect($defaults['models'])->toHaveKey('anthropic/claude-opus-4-6')
        ->and($defaults['models']['anthropic/claude-opus-4-6']['params']['cacheRetention'])->toBe('long')
        ->and($defaults['models'])->toHaveKey('anthropic/claude-opus-4-5');
});

test('defaults omit prompt caching when no Anthropic key', function () {
    TeamApiKey::factory()->create(['team_id' => $this->team->id, 'provider' => LlmProvider::OpenAi]);

    $defaults = $this->service->buildDefaults($this->server);

    expect($defaults)->not->toHaveKey('models');
});

test('OpenRouter memory search config includes hybrid search settings', function () {
    TeamApiKey::factory()->create(['team_id' => $this->team->id, 'provider' => LlmProvider::OpenRouter]);

    $defaults = $this->service->buildDefaults($this->server);

    $hybrid = $defaults['memorySearch']['query']['hybrid'];
    expect($hybrid['enabled'])->toBeTrue()
        ->and($hybrid['vectorWeight'])->toBe(0.7)
        ->and($hybrid['textWeight'])->toBe(0.3)
        ->and($hybrid['mmr']['enabled'])->toBeTrue()
        ->and($hybrid['temporalDecay']['enabled'])->toBeTrue()
        ->and($defaults['memorySearch']['experimental']['sessionMemory'])->toBeTrue();
});

test('defaults include all features when team has Anthropic + OpenAI keys', function () {
    TeamApiKey::factory()->create(['team_id' => $this->team->id, 'provider' => LlmProvider::Anthropic]);
    TeamApiKey::factory()->create(['team_id' => $this->team->id, 'provider' => LlmProvider::OpenAi]);

    $defaults = $this->service->buildDefaults($this->server);

    // Memory search via native OpenAI (no remote override)
    expect($defaults['memorySearch']['enabled'])->toBeTrue()
        ->and($defaults['memorySearch'])->not->toHaveKey('remote');

    // Prompt caching for Anthropic models
    expect($defaults['models'])->toHaveKey('anthropic/claude-opus-4-6');

    // Heartbeat routed to cheap OpenAI model
    expect($defaults['heartbeat']['model'])->toBe('openai/gpt-5-nano');

    // Compaction + context pruning always present
    expect($defaults['compaction']['mode'])->toBe('safeguard')
        ->and($defaults['contextPruning']['mode'])->toBe('cache-ttl');
});

test('defaults ignore inactive API keys', function () {
    TeamApiKey::factory()->inactive()->create(['team_id' => $this->team->id, 'provider' => LlmProvider::OpenAi]);
    TeamApiKey::factory()->create(['team_id' => $this->team->id, 'provider' => LlmProvider::Anthropic]);

    $defaults = $this->service->buildDefaults($this->server);

    // OpenAI key is inactive, so no native memory search
    expect($defaults['memorySearch']['enabled'])->toBeFalse()
        ->and($defaults)->not->toHaveKey('heartbeat');
});
