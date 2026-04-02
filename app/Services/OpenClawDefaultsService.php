<?php

namespace App\Services;

use App\Enums\LlmProvider;
use App\Models\Server;

class OpenClawDefaultsService
{
    /**
     * Build the `agents.defaults` config block based on the team's active API keys.
     *
     * @return array<string, mixed>
     */
    public function buildDefaults(Server $server): array
    {
        $team = $server->team;
        $activeProviders = $team->apiKeys()
            ->where('is_active', true)
            ->pluck('provider')
            ->unique()
            ->all();

        $hasOpenAi = in_array(LlmProvider::OpenAi, $activeProviders, true);
        $hasOpenRouter = in_array(LlmProvider::OpenRouter, $activeProviders, true);
        $hasAnthropic = in_array(LlmProvider::Anthropic, $activeProviders, true);

        $defaults = [
            'sandbox' => ['mode' => 'off'],
        ];

        $defaults['memorySearch'] = $this->buildMemorySearch($hasOpenAi, $hasOpenRouter);
        $defaults['compaction'] = $this->buildCompaction();
        $defaults['contextPruning'] = $this->buildContextPruning();

        $heartbeatModel = $this->resolveHeartbeatModel($hasOpenAi, $hasOpenRouter);
        if ($heartbeatModel) {
            $defaults['heartbeat'] = ['model' => $heartbeatModel];
            $defaults['subagents'] = [
                'model' => $heartbeatModel,
                'maxSpawnDepth' => 1,
                'maxChildrenPerAgent' => 5,
                'maxConcurrent' => 8,
                'runTimeoutSeconds' => 900,
            ];
        }

        if ($hasAnthropic) {
            $defaults['models'] = $this->buildPromptCaching();
        }

        return $defaults;
    }

    /**
     * Build minimal defaults for warm servers (no team context).
     *
     * @return array<string, mixed>
     */
    public function buildWarmDefaults(): array
    {
        return [
            'sandbox' => ['mode' => 'off'],
            'compaction' => $this->buildCompaction(),
            'contextPruning' => $this->buildContextPruning(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildMemorySearch(bool $hasOpenAi, bool $hasOpenRouter): array
    {
        if (! $hasOpenAi && ! $hasOpenRouter) {
            return ['enabled' => false];
        }

        $config = [
            'enabled' => true,
            'provider' => 'openai',
            'model' => 'text-embedding-3-small',
            'sources' => ['memory', 'sessions'],
            'query' => [
                'hybrid' => [
                    'enabled' => true,
                    'vectorWeight' => 0.7,
                    'textWeight' => 0.3,
                    'mmr' => ['enabled' => true, 'lambda' => 0.7],
                    'temporalDecay' => ['enabled' => true, 'halfLifeDays' => 30],
                ],
            ],
            'experimental' => ['sessionMemory' => true],
        ];

        if (! $hasOpenAi && $hasOpenRouter) {
            $config['remote'] = ['baseUrl' => 'https://openrouter.ai/api/v1/'];
        }

        return $config;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCompaction(): array
    {
        return [
            'mode' => 'safeguard',
            'memoryFlush' => [
                'enabled' => true,
                'softThresholdTokens' => 40000,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildContextPruning(): array
    {
        return [
            'mode' => 'cache-ttl',
            'ttl' => '6h',
            'keepLastAssistants' => 3,
        ];
    }

    private function resolveHeartbeatModel(bool $hasOpenAi, bool $hasOpenRouter): ?string
    {
        if ($hasOpenAi) {
            return 'openai/gpt-5-nano';
        }

        if ($hasOpenRouter) {
            return 'openrouter/z-ai/glm-4.7';
        }

        return null;
    }

    /**
     * @return array<string, array{params: array{cacheRetention: string}}>
     */
    private function buildPromptCaching(): array
    {
        return [
            'anthropic/claude-opus-4-6' => ['params' => ['cacheRetention' => 'long']],
            'anthropic/claude-opus-4-5' => ['params' => ['cacheRetention' => 'long']],
        ];
    }
}
