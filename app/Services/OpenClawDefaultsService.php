<?php

namespace App\Services;

use App\Enums\CloudProvider;
use App\Enums\LlmProvider;
use App\Enums\ModelTier;
use App\Models\Server;
use App\Services\Aws\AwsCredentials;

class OpenClawDefaultsService
{
    /**
     * Build the `agents.defaults` config block based on the team's active API keys.
     *
     * When the server belongs to an AWS team whose agents ALL run on Bedrock,
     * the server-wide defaults (heartbeat, subagents, memory search) route to
     * Bedrock too so no default-model traffic leaves the customer's cloud.
     * MIXED servers (some agents Bedrock, some not) keep the managed defaults
     * here — Bedrock agents still heartbeat in-cloud via their per-agent
     * override (Agent::openclawHeartbeatConfig()), but memory search stays on
     * the managed provider. That is a documented limitation for mixed teams.
     *
     * @return array<string, mixed>
     */
    public function buildDefaults(Server $server): array
    {
        $team = $server->team;
        $activeProviders = $team->llmApiKeys()
            ->where('is_active', true)
            ->pluck('provider')
            ->unique()
            ->all();

        $hasOpenAi = in_array(LlmProvider::OpenAi, $activeProviders, true);
        $hasOpenRouter = in_array(LlmProvider::OpenRouter, $activeProviders, true);
        $hasAnthropic = in_array(LlmProvider::Anthropic, $activeProviders, true);
        $allBedrock = $this->serverIsAllBedrock($server);

        $defaults = [
            'sandbox' => ['mode' => 'off'],
        ];

        $defaults['memorySearch'] = $allBedrock
            ? $this->buildBedrockMemorySearch()
            : $this->buildMemorySearch($hasOpenAi, $hasOpenRouter);
        $defaults['compaction'] = $this->buildCompaction();
        $defaults['contextPruning'] = $this->buildContextPruning();

        $heartbeatModel = $allBedrock
            ? self::bedrockAutomationModel()
            : $this->resolveHeartbeatModel($hasOpenAi, $hasOpenRouter);
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
     * Merge the amazon-bedrock plugin entry into an openclaw.json config when
     * the server's team runs on AWS and at least one agent uses Bedrock.
     *
     * `discovery.enabled: true` is REQUIRED for the plugin to authenticate via
     * the EC2 instance profile (IMDS); `discovery.region` pins the Bedrock
     * endpoint to the team's region. The merge is non-destructive: any other
     * plugin entries or amazon-bedrock config keys already present survive.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function applyBedrockPluginConfig(array $config, Server $server): array
    {
        if (! $this->serverHasBedrockAgent($server)) {
            return $config;
        }

        if (! isset($config['plugins']) || ! is_array($config['plugins']) || array_is_list($config['plugins'])) {
            $config['plugins'] = [];
        }
        if (! isset($config['plugins']['entries']) || ! is_array($config['plugins']['entries']) || array_is_list($config['plugins']['entries'])) {
            $config['plugins']['entries'] = [];
        }

        $region = AwsCredentials::regionForTeam($server->team);

        $config['plugins']['entries']['amazon-bedrock'] = array_replace_recursive(
            $config['plugins']['entries']['amazon-bedrock'] ?? [],
            [
                'enabled' => true,
                'config' => [
                    'discovery' => [
                        'enabled' => true,
                        'region' => $region,
                    ],
                ],
            ],
        );

        // Declare the Bedrock provider + concrete models explicitly. Discovery
        // (above) populates the provider catalog lazily, but the model resolver
        // only treats a Bedrock model id as "known" when the provider is also
        // present in `models.providers` — without this, agent turns fail with
        // "Unknown model: amazon-bedrock/...". The model ids come straight from
        // LlmProvider so they match the exact regional inference-profile ids.
        if (! isset($config['models']) || ! is_array($config['models'])) {
            $config['models'] = [];
        }
        if (! isset($config['models']['providers']) || ! is_array($config['models']['providers'])) {
            $config['models']['providers'] = [];
        }

        $models = [];
        foreach (LlmProvider::Bedrock->models() as $modelId) {
            // Strip the "amazon-bedrock/" provider prefix — the id here is the
            // bare inference-profile id under the provider entry.
            $profileId = str_replace('amazon-bedrock/', '', LlmProvider::Bedrock->openclawModel($modelId));
            $models[] = [
                'id' => $profileId,
                'contextWindow' => 200000,
                'maxTokens' => 8192,
            ];
        }

        $config['models']['providers']['amazon-bedrock'] = array_replace_recursive(
            $config['models']['providers']['amazon-bedrock'] ?? [],
            [
                'baseUrl' => "https://bedrock-runtime.{$region}.amazonaws.com",
                'api' => 'bedrock-converse-stream',
                'auth' => 'aws-sdk',
                'models' => $models,
            ],
        );

        return $config;
    }

    /**
     * Whether at least one agent on this server authenticates via Bedrock
     * (only meaningful on an AWS team — Bedrock is gated to AWS elsewhere).
     */
    public function serverHasBedrockAgent(Server $server): bool
    {
        if ($server->team?->cloudProvider() !== CloudProvider::Aws) {
            return false;
        }

        return $server->agents()->where('auth_provider', 'bedrock')->exists();
    }

    /**
     * Whether the server is on an AWS team and EVERY agent uses Bedrock.
     * Servers with no agents yet resolve to false (managed defaults apply
     * until the first agent lands and the config is rebuilt).
     */
    public function serverIsAllBedrock(Server $server): bool
    {
        if ($server->team?->cloudProvider() !== CloudProvider::Aws) {
            return false;
        }

        if (! $server->agents()->exists()) {
            return false;
        }

        return ! $server->agents()
            ->where(fn ($query) => $query->where('auth_provider', '!=', 'bedrock')->orWhereNull('auth_provider'))
            ->exists();
    }

    /**
     * The OpenClaw model id used for Bedrock heartbeats and subagents —
     * Bedrock Haiku through the regional inference profile.
     */
    public static function bedrockAutomationModel(): string
    {
        return LlmProvider::Bedrock->openclawModel(ModelTier::Bedrock->heartbeatModel());
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
            'query' => $this->buildMemorySearchQuery(),
            'experimental' => ['sessionMemory' => true],
        ];

        if (! $hasOpenAi && $hasOpenRouter) {
            $config['remote'] = ['baseUrl' => 'https://openrouter.ai/api/v1/'];
        }

        return $config;
    }

    /**
     * In-cloud memory search for all-Bedrock AWS servers. The bedrock
     * provider embeds via the instance profile (default model
     * amazon.titan-embed-text-v2:0) so no embedding traffic leaves AWS.
     *
     * @return array<string, mixed>
     */
    private function buildBedrockMemorySearch(): array
    {
        return [
            'enabled' => true,
            'provider' => 'bedrock',
            'sources' => ['memory', 'sessions'],
            'query' => $this->buildMemorySearchQuery(),
            'experimental' => ['sessionMemory' => true],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildMemorySearchQuery(): array
    {
        return [
            'hybrid' => [
                'enabled' => true,
                'vectorWeight' => 0.7,
                'textWeight' => 0.3,
                'mmr' => ['enabled' => true, 'lambda' => 0.7],
                'temporalDecay' => ['enabled' => true, 'halfLifeDays' => 30],
            ],
        ];
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
