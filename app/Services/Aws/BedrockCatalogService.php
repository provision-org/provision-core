<?php

namespace App\Services\Aws;

use Aws\Bedrock\BedrockClient;
use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\Exception\AwsException;

/**
 * Reads a team's Amazon Bedrock account: which models are actually invocable
 * (so we never offer a model the account can't call), and a save-time check
 * that a chosen model really answers. Bound to one team's AwsCredentials.
 *
 * Model ids here are the RAW AWS ids (foundation-model ids like
 * "openai.gpt-oss-120b-1:0" or regional inference-profile ids like
 * "us.anthropic.claude-sonnet-4-6"). The "bedrock:" prefix used elsewhere in
 * the app is added when the id is stored on an agent/team — not here.
 */
class BedrockCatalogService
{
    /**
     * Preference order for auto-detecting a team's default model — strongest
     * first. Claude leads (best quality) but needs the Anthropic use-case form;
     * everything after it is form-free and works on a fresh AWS account. Only
     * ids present in the account's invocable catalog are considered, and each
     * is invoke-checked in turn, so the first that actually answers wins.
     *
     * @var list<string>
     */
    private const DEFAULT_PREFERENCE = [
        'us.anthropic.claude-sonnet-4-6',
        'us.anthropic.claude-opus-4-6-v1',
        'us.anthropic.claude-haiku-4-5-20251001-v1:0',
        'deepseek.v3.2',
        'zai.glm-5',
        'qwen.qwen3-vl-235b-a22b',
        'moonshotai.kimi-k2.5',
        'minimax.minimax-m2.5',
        'openai.gpt-oss-120b-1:0',
        'meta.llama3-70b-instruct-v1:0',
        'amazon.nova-pro-v1:0',
    ];

    private BedrockClient $client;

    private BedrockRuntimeClient $runtime;

    public function __construct(
        private readonly AwsCredentials $credentials,
        ?BedrockClient $client = null,
        ?BedrockRuntimeClient $runtime = null,
    ) {
        $clientConfig = [
            'version' => 'latest',
            'region' => $credentials->region,
            'credentials' => [
                'key' => $credentials->keyId,
                'secret' => $credentials->secret,
            ],
        ];

        $this->client = $client ?? new BedrockClient($clientConfig);
        $this->runtime = $runtime ?? new BedrockRuntimeClient($clientConfig);
    }

    /**
     * List every model the account can actually invoke on demand in this
     * region: streaming-capable TEXT foundation models plus the system-defined
     * inference profiles (the "us."/"global." ids newer Claude models require).
     * Deduped by id, sorted by provider then label.
     *
     * @return list<array{id: string, label: string, provider: string, requiresUseCaseForm: bool}>
     */
    public function listModels(): array
    {
        $models = [];

        $foundation = $this->call('ListFoundationModels', fn (): mixed => $this->client->listFoundationModels([
            'byOutputModality' => 'TEXT',
        ]));

        foreach ($foundation['modelSummaries'] ?? [] as $summary) {
            $inferenceTypes = $summary['inferenceTypesSupported'] ?? [];
            if (! in_array('ON_DEMAND', $inferenceTypes, true)) {
                continue;
            }
            if (($summary['responseStreamingSupported'] ?? true) === false) {
                continue;
            }

            $id = $summary['modelId'] ?? null;
            if (! $id) {
                continue;
            }

            $provider = $summary['providerName'] ?: $this->providerFromId($id);
            $models[$id] = [
                'id' => $id,
                'label' => $summary['modelName'] ?: $id,
                'provider' => $provider,
                'requiresUseCaseForm' => $this->isAnthropic($provider, $id),
            ];
        }

        $profiles = $this->call('ListInferenceProfiles', fn (): mixed => $this->client->listInferenceProfiles([
            'maxResults' => 100,
        ]));

        foreach ($profiles['inferenceProfileSummaries'] ?? [] as $summary) {
            if (($summary['type'] ?? 'SYSTEM_DEFINED') !== 'SYSTEM_DEFINED') {
                continue;
            }

            $id = $summary['inferenceProfileId'] ?? null;
            if (! $id) {
                continue;
            }

            $provider = $this->providerFromId($id);
            $models[$id] = [
                'id' => $id,
                'label' => $summary['inferenceProfileName'] ?: $id,
                'provider' => $provider,
                'requiresUseCaseForm' => $this->isAnthropic($provider, $id),
            ];
        }

        $list = array_values($models);
        usort($list, fn (array $a, array $b): int => [$a['provider'], $a['label']] <=> [$b['provider'], $b['label']]);

        return $list;
    }

    /**
     * Confirm a specific model actually answers via ConverseStream — the same
     * path OpenClaw uses. Returns ok=false with a readable error (and
     * useCaseForm=true for Anthropic's "submit use case details" gate) so the
     * UI can guide the user instead of failing silently at deploy time.
     *
     * @return array{ok: bool, error?: string, useCaseForm?: bool}
     */
    public function verifyModel(string $modelId): array
    {
        try {
            $result = $this->runtime->converseStream([
                'modelId' => $modelId,
                'messages' => [[
                    'role' => 'user',
                    'content' => [['text' => 'Reply with OK.']],
                ]],
                'inferenceConfig' => ['maxTokens' => 8],
            ]);

            // Drain the event stream — a model-access error surfaces mid-stream
            // rather than on the initial call, so we must iterate to see it.
            foreach ($result['stream'] as $event) {
                unset($event);
            }

            return ['ok' => true];
        } catch (AwsException $e) {
            $message = $e->getAwsErrorMessage() ?? $e->getMessage();
            $useCaseForm = $e->getAwsErrorCode() === 'ResourceNotFoundException'
                && str_contains($message, 'use case');

            return array_filter([
                'ok' => false,
                'error' => $message,
                'useCaseForm' => $useCaseForm ?: null,
            ], fn ($v): bool => $v !== null);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Auto-detect the best default: the strongest model in DEFAULT_PREFERENCE
     * that is both present in the account catalog and passes the invoke check.
     * Caps the number of invoke checks so a locked-down account degrades fast.
     * Returns null when nothing in the preference list is usable.
     *
     * @param  list<array{id: string, label: string, provider: string, requiresUseCaseForm: bool}>|null  $catalog
     */
    public function resolveBestDefaultModel(?array $catalog = null, int $maxChecks = 4): ?string
    {
        $catalog ??= $this->listModels();
        $available = array_column($catalog, 'id');

        $checks = 0;
        foreach (self::DEFAULT_PREFERENCE as $candidate) {
            if (! in_array($candidate, $available, true)) {
                continue;
            }
            if ($checks >= $maxChecks) {
                break;
            }
            $checks++;
            if (($this->verifyModel($candidate)['ok'] ?? false) === true) {
                return $candidate;
            }
        }

        return null;
    }

    private function isAnthropic(string $provider, string $id): bool
    {
        return str_contains(strtolower($provider), 'anthropic') || str_contains($id, 'anthropic');
    }

    private function providerFromId(string $id): string
    {
        // Ids look like "us.anthropic.claude-…", "global.openai.…" or
        // "deepseek.v3.2" — the vendor token is the segment after any region
        // prefix. Title-case it for display.
        $parts = explode('.', $id);
        $vendor = $parts[0];
        if (in_array($vendor, ['us', 'eu', 'apac', 'global'], true) && isset($parts[1])) {
            $vendor = $parts[1];
        }

        return ucfirst($vendor);
    }

    /**
     * @return array<string, mixed>
     */
    private function call(string $operation, callable $fn): array
    {
        try {
            $result = $fn();

            return method_exists($result, 'toArray') ? $result->toArray() : (array) $result;
        } catch (AwsException $e) {
            $message = $e->getAwsErrorMessage() ?? $e->getMessage();

            throw new \RuntimeException("AWS {$operation} failed: {$message}", 0, $e);
        }
    }
}
