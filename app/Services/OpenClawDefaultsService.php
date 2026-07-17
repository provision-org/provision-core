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
     * Sentinel apiKey the amazon-bedrock-mantle plugin recognises as "mint the
     * bearer token from IAM at runtime" (its MANTLE_IAM_TOKEN_MARKER). Seeding it
     * under models.providers makes the standard credential lookup return a
     * non-empty key, which is the precondition for the plugin's IAM-mint exchange.
     */
    private const MANTLE_IAM_TOKEN_MARKER = '__amazon_bedrock_mantle_iam__';

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

        // Bedrock Mantle teams route through the amazon-bedrock-mantle provider
        // (native Anthropic/OpenAI APIs, bearer-token auth, no use-case form)
        // instead of the classic ConverseStream plugin.
        if ($this->serverBedrockMode($server) === 'mantle') {
            return $this->applyMantleProviderConfig($config, $server);
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
        foreach ($this->serverBedrockModelIds($server) as $profileId) {
            $models[] = [
                'id' => $profileId,
                // OpenClaw 2026.7.1 requires a string `name` on every provider
                // model entry — omitting it fails config validation and blocks
                // gateway startup. The inference-profile id IS the model string.
                'name' => $profileId,
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
     * Collect the raw Bedrock model ids (no "amazon-bedrock/" prefix) that must
     * be declared under `models.providers.amazon-bedrock.models` so the resolver
     * treats them as known: every model any Bedrock agent on the server actually
     * references, plus the team default, plus the tier defaults as a safety net.
     *
     * @return list<string>
     */
    private function serverBedrockModelIds(Server $server): array
    {
        $ids = [];

        $toRaw = function (?string $internalId) use (&$ids): void {
            if ($internalId === null || $internalId === '') {
                return;
            }
            if (LlmProvider::forModel($internalId) !== LlmProvider::Bedrock) {
                return;
            }
            $ids[] = str_replace('amazon-bedrock/', '', LlmProvider::Bedrock->openclawModel($internalId));
        };

        // Team-wide default the customer picked.
        $toRaw(AwsCredentials::defaultBedrockModelForTeam($server->team));

        // Every Bedrock agent's primary + fallbacks.
        $server->agents()->where('auth_provider', 'bedrock')->get()
            ->each(function ($agent) use ($toRaw): void {
                $toRaw($agent->model_primary);
                foreach ($agent->model_fallbacks ?? [] as $fallback) {
                    $toRaw($fallback);
                }
            });

        // Tier defaults — guarantees a usable entry even before any agent lands.
        foreach (LlmProvider::Bedrock->models() as $modelId) {
            $toRaw($modelId);
        }

        return array_values(array_unique($ids));
    }

    /**
     * Which Bedrock backend the server's models target: 'mantle' when any
     * team-default or agent model is a "mantle:<raw>" id, 'classic' when a
     * "bedrock:<raw>" id is present, null when the server has no Bedrock model
     * pinned yet (managed defaults still apply). Mantle wins if both appear.
     */
    private function serverBedrockMode(Server $server): ?string
    {
        $ids = [AwsCredentials::defaultBedrockModelForTeam($server->team)];

        foreach ($server->agents()->where('auth_provider', 'bedrock')->get() as $agent) {
            $ids[] = $agent->model_primary;
            foreach ($agent->model_fallbacks ?? [] as $fallback) {
                $ids[] = $fallback;
            }
        }

        $ids = array_filter($ids);

        if (array_filter($ids, fn (string $id): bool => str_starts_with($id, 'mantle:'))) {
            return 'mantle';
        }
        if (array_filter($ids, fn (string $id): bool => str_starts_with($id, 'bedrock:'))) {
            return 'classic';
        }

        return null;
    }

    /**
     * Enable the amazon-bedrock-mantle provider with instance-role auto-minted
     * bearer-token auth and an EXPLICITLY declared model catalog.
     *
     * Why explicit models, not discovery: the Mantle plugin CAN discover its
     * catalog from the region's `/v1/models` at runtime, but a discovered
     * (auto-loaded, non-bundled) provider's rows only register once the plugin is
     * "trusted" — and per the OpenClaw docs the only ways to trust it are
     * `plugins.allow` or recorded install provenance. `plugins.allow` is an
     * EXCLUSIVE allowlist (no "*" wildcard; bundled default-on plugins like the
     * `browser` plugin are dropped unless every id is re-listed), so seeding it
     * silently disables the agent browser — verified on a live box. Declaring the
     * models statically under `models.providers` (exactly how the classic
     * amazon-bedrock path works) makes them KNOWN without any allowlist, so all
     * bundled plugins keep working.
     *
     * `apiKey` is the plugin's IAM marker sentinel: the gateway only runs the
     * plugin's `prepareRuntimeAuth` IAM-mint exchange when the standard credential
     * lookup FIRST returns a non-empty apiKey (core getRuntimeAuthForModel:
     * `if (!resolvedAuth.apiKey) return`). That lookup reads static
     * `models.providers.<id>.apiKey`, so we seed the marker here;
     * `resolveMantleRuntimeBearerToken` recognises it and mints the real bearer
     * from the EC2 instance-profile role — no stored key.
     *
     * The region is supplied to the gateway daemon via the `AWS_REGION` env var
     * (ServerSetupScriptService sets it in the systemd override) so the SigV4 mint
     * succeeds; `baseUrl` also pins the regional endpoint here. Verified end-to-end
     * on a clean deploy: the agent invokes `amazon-bedrock-mantle/...` (status 200)
     * AND the browser plugin stays enabled.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function applyMantleProviderConfig(array $config, Server $server): array
    {
        if (! isset($config['plugins']) || ! is_array($config['plugins']) || array_is_list($config['plugins'])) {
            $config['plugins'] = [];
        }
        if (! isset($config['plugins']['entries']) || ! is_array($config['plugins']['entries']) || array_is_list($config['plugins']['entries'])) {
            $config['plugins']['entries'] = [];
        }

        // enabled=true is enough to load + trust the bundled-style provider plugin
        // (no plugins.allow needed). discovery.enabled is harmless and left on so a
        // future release that auto-trusts installed plugins can supplement the list;
        // our explicit models below always win (mergeImplicitMantleProvider keeps
        // existing.models when non-empty).
        $config['plugins']['entries']['amazon-bedrock-mantle'] = array_replace_recursive(
            $config['plugins']['entries']['amazon-bedrock-mantle'] ?? [],
            [
                'enabled' => true,
                'config' => [
                    'discovery' => [
                        'enabled' => true,
                    ],
                ],
            ],
        );

        if (! isset($config['models']) || ! is_array($config['models'])) {
            $config['models'] = [];
        }
        if (! isset($config['models']['providers']) || ! is_array($config['models']['providers'])) {
            $config['models']['providers'] = [];
        }

        $region = AwsCredentials::regionForTeam($server->team);

        $models = [];
        foreach ($this->serverMantleModelIds($server) as $rawId) {
            $models[] = [
                'id' => $rawId,
                // Every provider model entry needs a string `name` (OpenClaw 2026.7.1
                // config validation) — the raw id doubles as the label.
                'name' => $rawId,
                // Per-model API: Anthropic ids speak the native Messages API; every
                // other Mantle model (OSS/Nova/etc.) is OpenAI-completions. A single
                // provider-level value can't serve a mixed catalog.
                'api' => str_starts_with($rawId, 'anthropic.') ? 'anthropic-messages' : 'openai-completions',
                'input' => str_starts_with($rawId, 'anthropic.') ? ['text', 'image'] : ['text'],
                'contextWindow' => str_starts_with($rawId, 'anthropic.') ? 1000000 : 128000,
                'maxTokens' => str_starts_with($rawId, 'anthropic.') ? 128000 : 8192,
            ];
        }

        $config['models']['providers']['amazon-bedrock-mantle'] = array_replace_recursive(
            $config['models']['providers']['amazon-bedrock-mantle'] ?? [],
            [
                'baseUrl' => "https://bedrock-mantle.{$region}.api.aws/v1",
                'api' => 'openai-completions',
                'auth' => 'api-key',
                'apiKey' => self::MANTLE_IAM_TOKEN_MARKER,
                'models' => $models,
            ],
        );

        return $config;
    }

    /**
     * Collect the raw Mantle model ids (no "mantle:"/"amazon-bedrock-mantle/"
     * prefix) that must be declared under `models.providers.amazon-bedrock-mantle`
     * so the resolver treats them as known: the team default plus every Mantle
     * model any agent on the server references, plus the pinned team default's
     * Claude Sonnet 5 as a floor.
     *
     * @return list<string>
     */
    private function serverMantleModelIds(Server $server): array
    {
        $ids = [];

        $toRaw = function (?string $internalId) use (&$ids): void {
            if ($internalId === null || ! str_starts_with($internalId, 'mantle:')) {
                return;
            }
            $ids[] = substr($internalId, strlen('mantle:'));
        };

        // Team-wide default the customer picked.
        $toRaw(AwsCredentials::defaultBedrockModelForTeam($server->team));

        // Every Bedrock agent's primary + fallbacks that target Mantle.
        $server->agents()->where('auth_provider', 'bedrock')->get()
            ->each(function ($agent) use ($toRaw): void {
                $toRaw($agent->model_primary);
                foreach ($agent->model_fallbacks ?? [] as $fallback) {
                    $toRaw($fallback);
                }
            });

        // Floor: guarantee Claude Sonnet 5 is always usable even before any agent
        // lands (the wizard's default Mantle model).
        $ids[] = 'anthropic.claude-sonnet-5';

        return array_values(array_unique($ids));
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
