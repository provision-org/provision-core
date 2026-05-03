<?php

namespace App\Enums;

enum LlmProvider: string
{
    case Anthropic = 'anthropic';
    case OpenAi = 'openai';
    case OpenRouter = 'open_router';
    case OpenAiCodex = 'openai_codex';

    public const DEFAULT_MODEL = 'z-ai/glm-4.7';

    /** Cheapest model that reliably handles tool calling — used for crons & heartbeats. */
    public const AUTOMATION_MODEL = 'openrouter/anthropic/claude-haiku-4.5';

    public function envKeyName(): string
    {
        return match ($this) {
            self::Anthropic => 'ANTHROPIC_API_KEY',
            self::OpenAi => 'OPENAI_API_KEY',
            self::OpenRouter => 'OPENROUTER_API_KEY',
            self::OpenAiCodex => 'OPENAI_API_KEY',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Anthropic => 'Anthropic',
            self::OpenAi => 'OpenAI',
            self::OpenRouter => 'OpenRouter',
            self::OpenAiCodex => 'ChatGPT Subscription',
        };
    }

    /**
     * @return array<string>
     */
    public function models(): array
    {
        return match ($this) {
            self::Anthropic => [
                'claude-opus-4-6',
                'claude-opus-4-5',
                'claude-sonnet-4-6',
                'claude-haiku-4-5',
            ],
            self::OpenAi => [
                'gpt-5.4',
                'gpt-5.2-codex',
                'gpt-5-nano',
                'gpt-5-mini',
            ],
            self::OpenRouter => [
                'z-ai/glm-4.7',
                'z-ai/glm-5',
                'moonshotai/kimi-k2-thinking',
                'moonshotai/kimi-k2.5',
                'minimax/minimax-m2.5',
            ],
            self::OpenAiCodex => [
                'gpt-5.5',
                'gpt-5.5-pro',
                'gpt-5.4',
                'gpt-5.4-pro',
                'gpt-5.4-mini',
            ],
        };
    }

    public static function isChatGptSubscriptionModel(string $modelId): bool
    {
        return in_array($modelId, self::OpenAiCodex->models(), true);
    }

    /**
     * @return array<string>
     */
    public static function allModels(): array
    {
        return collect(self::cases())
            ->flatMap(fn (self $provider) => $provider->models())
            ->all();
    }

    public static function forModel(string $modelId): ?self
    {
        foreach (self::cases() as $provider) {
            if (in_array($modelId, $provider->models(), true)) {
                return $provider;
            }
        }

        return null;
    }

    /**
     * Prefix a model ID for OpenClaw config (e.g. "openrouter/z-ai/glm-4.7").
     */
    /**
     * Prefix a model ID for OpenClaw config, routing all models through OpenRouter.
     *
     * OpenRouter uses provider-prefixed model IDs (e.g. "anthropic/claude-opus-4-6"),
     * and OpenClaw uses "openrouter/" prefix to select the OpenRouter API key.
     */
    public function openclawModel(string $modelId): string
    {
        return match ($this) {
            self::OpenRouter => "openrouter/{$modelId}",
            self::Anthropic => "openrouter/anthropic/{$modelId}",
            self::OpenAi => "openrouter/openai/{$modelId}",
            self::OpenAiCodex => "openai-codex/{$modelId}",
        };
    }
}
