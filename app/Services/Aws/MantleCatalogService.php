<?php

namespace App\Services\Aws;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;

/**
 * Reads a team's Amazon Bedrock **Mantle** catalog and verifies models, using a
 * short-term bearer token minted from the team's AWS credentials (no server
 * required). Mantle exposes a single OpenAI-compatible endpoint at
 * `/v1` plus an Anthropic-compatible path at `/anthropic`; auth is the bearer
 * token, so unlike classic Bedrock there is no per-model use-case-form gate.
 *
 * Model ids are the RAW Mantle ids ("anthropic.claude-sonnet-5",
 * "openai.gpt-oss-120b"); the app's "bedrock:" prefix is added when stored.
 */
class MantleCatalogService
{
    /** Regions where the Mantle endpoint is available. */
    public const SUPPORTED_REGIONS = [
        'us-east-1', 'us-east-2', 'us-west-2',
        'ap-northeast-1', 'ap-south-1', 'ap-southeast-3',
        'eu-central-1', 'eu-west-1', 'eu-west-2', 'eu-south-1', 'eu-north-1',
        'sa-east-1',
    ];

    /**
     * Auto-detect preference, strongest first. Claude leads (best quality and
     * every available Claude row supports zero-data-retention); form-free OSS
     * models follow as fallbacks for locked-down accounts.
     *
     * @var list<string>
     */
    private const DEFAULT_PREFERENCE = [
        'anthropic.claude-sonnet-5',
        'anthropic.claude-opus-4-8',
        'anthropic.claude-haiku-4-5',
        'deepseek.v3.2',
        'zai.glm-5',
        'qwen.qwen3-235b-a22b-2507',
        'openai.gpt-oss-120b',
    ];

    /** Vendor token → display name; falls back to ucfirst() for anything new. */
    private const PROVIDER_LABELS = [
        'openai' => 'OpenAI', 'anthropic' => 'Anthropic', 'google' => 'Google',
        'mistral' => 'Mistral', 'qwen' => 'Qwen', 'deepseek' => 'DeepSeek',
        'moonshotai' => 'Moonshot', 'minimax' => 'MiniMax', 'nvidia' => 'NVIDIA',
        'xai' => 'xAI', 'zai' => 'Z.ai', 'writer' => 'Writer', 'meta' => 'Meta',
        'amazon' => 'Amazon',
    ];

    public function __construct(
        private readonly AwsCredentials $credentials,
        private readonly MantleTokenGenerator $tokens,
        private readonly HttpFactory $http,
    ) {}

    /** Base URL for the team's region, e.g. https://bedrock-mantle.us-east-1.api.aws */
    public function baseUrl(): string
    {
        return "https://bedrock-mantle.{$this->credentials->region}.api.aws";
    }

    /**
     * List every model the account can select in this region. Skips ids the
     * catalog marks unavailable; carries a `zeroRetention` flag (true when the
     * model supports the "none" data-retention mode — the ZDR knob HIPAA teams
     * need). Sorted by provider then label.
     *
     * @return list<array{id: string, label: string, provider: string, requiresUseCaseForm: bool, zeroRetention: bool}>
     */
    public function listModels(): array
    {
        $response = $this->request()->get($this->baseUrl().'/v1/models');

        if ($response->failed()) {
            // Surface the Mantle/IAM error verbatim (Laravel's RequestException
            // truncates it) so the wizard can show the exact missing permission.
            $message = $response->json('error.message')
                ?? $response->json('message')
                ?? "HTTP {$response->status()}";

            throw new \RuntimeException("Amazon Bedrock Mantle rejected the request: {$message}");
        }

        $models = [];
        foreach ($response->json('data', []) as $entry) {
            $id = $entry['id'] ?? null;
            if (! $id || ($entry['status'] ?? 'available') !== 'available') {
                continue;
            }

            $provider = $this->providerLabel($id);
            $models[] = [
                'id' => $id,
                'label' => $this->modelLabel($id),
                'provider' => $provider,
                // Mantle has no use-case form; keep the key so the UI contract
                // matches the classic catalog, always false here.
                'requiresUseCaseForm' => false,
                'zeroRetention' => in_array('none', $entry['data_retention']['allowed_modes'] ?? [], true),
            ];
        }

        usort($models, fn (array $a, array $b): int => [$a['provider'], $a['label']] <=> [$b['provider'], $b['label']]);

        return $models;
    }

    /**
     * Confirm a model actually answers. Anthropic ids go to the native
     * `/anthropic/v1/messages` path; everything else to the OpenAI-compatible
     * `/v1/chat/completions`. A catalog entry can be "available" yet still
     * reject invocation (the GPT-5.x models do today), so the UI must verify
     * rather than trust the listing.
     *
     * @return array{ok: bool, error?: string}
     */
    public function verifyModel(string $modelId): array
    {
        try {
            if (str_starts_with($modelId, 'anthropic.')) {
                $response = $this->request()
                    ->withHeaders(['anthropic-version' => '2023-06-01'])
                    ->post($this->baseUrl().'/anthropic/v1/messages', [
                        'model' => $modelId,
                        'max_tokens' => 8,
                        'messages' => [['role' => 'user', 'content' => 'Reply with OK.']],
                    ]);
            } else {
                $response = $this->request()->post($this->baseUrl().'/v1/chat/completions', [
                    'model' => $modelId,
                    'max_tokens' => 8,
                    'messages' => [['role' => 'user', 'content' => 'Reply with OK.']],
                ]);
            }

            if ($response->successful()) {
                return ['ok' => true];
            }

            $message = $response->json('error.message') ?? "HTTP {$response->status()}";

            return ['ok' => false, 'error' => $message];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Strongest model in DEFAULT_PREFERENCE that is both listed and verifies.
     * Caps invoke checks so a locked-down account resolves fast. Null when
     * nothing usable is found.
     *
     * @param  list<array{id: string, label: string, provider: string, requiresUseCaseForm: bool, zeroRetention: bool}>|null  $catalog
     */
    public function resolveBestDefaultModel(?array $catalog = null, int $maxChecks = 3): ?string
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

    private function request(): PendingRequest
    {
        return $this->http
            ->withHeaders(['x-api-key' => $this->tokens->generate($this->credentials)])
            ->timeout(20);
    }

    private function providerLabel(string $id): string
    {
        $vendor = explode('.', $id)[0];

        return self::PROVIDER_LABELS[$vendor] ?? ucfirst($vendor);
    }

    private function modelLabel(string $id): string
    {
        // "anthropic.claude-sonnet-5" -> "Claude Sonnet 5"
        $name = str_contains($id, '.') ? substr($id, strpos($id, '.') + 1) : $id;

        return ucwords(str_replace('-', ' ', $name));
    }
}
