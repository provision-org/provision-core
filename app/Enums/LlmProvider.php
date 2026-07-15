<?php

namespace App\Enums;

enum LlmProvider: string
{
    case Anthropic = 'anthropic';
    case OpenAi = 'openai';
    case OpenRouter = 'open_router';
    case OpenAiCodex = 'openai_codex';
    case Bedrock = 'bedrock';

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
            // Bedrock authenticates via the EC2 instance profile — there is no
            // API key to push to the server. This placeholder is never written:
            // Bedrock has no TeamApiKey llm row, so the env-key collection
            // paths (which iterate llmApiKeys) never reach this arm.
            self::Bedrock => 'AWS_BEDROCK_INSTANCE_PROFILE',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Anthropic => 'Anthropic',
            self::OpenAi => 'OpenAI',
            self::OpenRouter => 'OpenRouter',
            self::OpenAiCodex => 'ChatGPT Subscription',
            self::Bedrock => 'AWS Bedrock',
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
            self::Bedrock => [
                'bedrock-claude-opus-4-6',
                'bedrock-claude-sonnet-4-6',
                'bedrock-claude-haiku-4-5',
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
        // OpenRouter uses dotted version segments (e.g. claude-haiku-4.5) while
        // our DB ids use hyphens (claude-haiku-4-5). Convert the trailing
        // -N-M version pair to -N.M so the model id is recognized upstream.
        $forOpenRouter = preg_replace('/-(\d+)-(\d+)$/', '-$1.$2', $modelId) ?? $modelId;

        return match ($this) {
            self::OpenRouter => "openrouter/{$modelId}",
            self::Anthropic => "openrouter/anthropic/{$forOpenRouter}",
            self::OpenAi => "openrouter/openai/{$forOpenRouter}",
            self::OpenAiCodex => "openai-codex/{$modelId}",
            // Direct Bedrock routing — never via OpenRouter. Model traffic
            // stays inside the customer's AWS account. OpenClaw's provider
            // prefix is "amazon-bedrock" and the model reference is a US
            // regional inference profile ("us." prefix). Bedrock-native IDs
            // keep HYPHENS and end in "-v1:0" — no dot conversion, e.g.
            // bedrock-claude-sonnet-4-6 → amazon-bedrock/us.anthropic.claude-sonnet-4-6-v1:0
            self::Bedrock => 'amazon-bedrock/us.anthropic.'.str_replace('bedrock-', '', $modelId).'-v1:0',
        };
    }
}
