<?php

namespace App\Jobs;

use App\Contracts\CommandExecutor;
use App\Enums\CloudProvider;
use App\Enums\LlmProvider;
use App\Models\Server;
use App\Models\TeamApiKey;
use App\Services\Aws\AwsCredentials;
use App\Services\HarnessManager;
use App\Services\OpenClawDefaultsService;
use App\Support\OpenClawConfig;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;

class UpdateEnvOnServerJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public Server $server) {}

    public function handle(HarnessManager $harnessManager, OpenClawDefaultsService $defaultsService): void
    {
        $team = $this->server->team;
        // LLM keys only — cloud keys (e.g. the BYO-AWS credential row) carry a
        // raw string provider and must never be pushed to the agent .env.
        // Bedrock needs no key at all (EC2 instance-profile auth), so it is
        // naturally absent here.
        $activeKeys = $team->llmApiKeys()->where('is_active', true)->get();
        $executor = $harnessManager->resolveExecutor($this->server);

        $envLines = [];
        $envConfigKeys = [];

        // Add API keys as environment variables
        foreach ($activeKeys as $apiKey) {
            $envLines[] = "{$apiKey->provider->envKeyName()}={$apiKey->api_key}";
            $envConfigKeys[$apiKey->provider->envKeyName()] = $apiKey->api_key;
        }

        // If team has OpenRouter but no native OpenAI key, alias it for embedding auth
        $hasOpenAi = $activeKeys->contains('provider', LlmProvider::OpenAi);
        $openRouterKey = $activeKeys->firstWhere('provider', LlmProvider::OpenRouter);

        if (! $hasOpenAi && $openRouterKey) {
            $envLines[] = "OPENAI_API_KEY={$openRouterKey->api_key}";
            $envConfigKeys['OPENAI_API_KEY'] = $openRouterKey->api_key;
        }

        // Add managed API key if no user-provided OpenRouter key exists
        // OpenRouter sub-keys work as auth for all providers (anthropic, openai, etc.)
        $managedKey = $team->managedApiKey;
        $hasAnthropic = $activeKeys->contains('provider', LlmProvider::Anthropic);
        if ($managedKey && ! $activeKeys->contains('provider', LlmProvider::OpenRouter)) {
            $envLines[] = "OPENROUTER_API_KEY={$managedKey->api_key}";
            $envConfigKeys['OPENROUTER_API_KEY'] = $managedKey->api_key;

            if (! $hasOpenAi) {
                $envLines[] = "OPENAI_API_KEY={$managedKey->api_key}";
                $envConfigKeys['OPENAI_API_KEY'] = $managedKey->api_key;
            }

            if (! $hasAnthropic) {
                $envLines[] = "ANTHROPIC_API_KEY={$managedKey->api_key}";
                $envConfigKeys['ANTHROPIC_API_KEY'] = $managedKey->api_key;
            }
        }

        // BYO-AWS: the OpenClaw amazon-bedrock plugin resolves its endpoint
        // from AWS_REGION. Only the region is pushed — the AWS key/secret are
        // NEVER written to the box (Bedrock auth is the EC2 instance profile).
        if ($team->cloudProvider() === CloudProvider::Aws) {
            $awsRegion = AwsCredentials::regionForTeam($team);
            $envLines[] = "AWS_REGION={$awsRegion}";
            $envConfigKeys['AWS_REGION'] = $awsRegion;
        }

        // Add custom env vars (only to .env file, not openclaw.json env section)
        foreach ($team->envVars as $envVar) {
            $envLines[] = "{$envVar->key}={$envVar->value}";
        }

        // Agent-specific vars (MAILBOXKIT_INBOX_ID, MAILBOXKIT_EMAIL, GH_CONFIG_DIR)
        // are in per-agent .env files — NOT in the shared .env to avoid cross-contamination.

        // Add Provision API URL to the global .env so OpenClaw skill eligibility
        // checks can find it. The token is agent-specific (in per-agent .env) but
        // the URL is the same for all agents. The skill also loads per-agent .env
        // at runtime via dotenv, so the token is resolved there.
        $envLines[] = 'PROVISION_API_URL='.config('app.url');
        // PROVISION_AGENT_TOKEN is set per-agent — use a placeholder here so
        // OpenClaw's skill check sees the env var as "set" for eligibility
        $envLines[] = 'PROVISION_AGENT_TOKEN=agent-specific';

        $envContent = implode("\n", $envLines)."\n";

        $executor->writeFile('/root/.openclaw/.env', $envContent);

        // Recalculate agent defaults and set LLM provider keys in openclaw.json
        $this->updateAgentDefaults($executor, $defaultsService, $envConfigKeys);

        // Store the keys in each agent's SQLite auth store — the only place
        // OpenClaw 2026.7.x reads credentials from. The env/.env writes above
        // remain as the documented fallback (embeddings, utility lookups).
        $this->deployAuthCredentials($executor, $activeKeys, $managedKey?->api_key);

        // Workaround for OpenClaw binary having hardcoded /home/sprite/ paths (#24016)
        $executor->exec('mkdir -p /home/sprite && ln -sfn /root/.openclaw /home/sprite/.openclaw');

        // Install dotenv for provision-tasks skill
        $executor->exec('npm install -g dotenv 2>/dev/null || true');

        RestartGatewayJob::dispatch($this->server);
    }

    /**
     * Inject provider API keys into each agent's auth store via
     * `openclaw models auth paste-api-key` (reads the secret from piped
     * stdin, upserts the per-agent SQLite auth_profile_store, and refreshes
     * a running gateway).
     *
     * The legacy mechanism — writing auth-profiles.json files — is dead on
     * OpenClaw 2026.7.x: the runtime never reads those files, and their
     * presence flips "has auth" heuristics to true while actual key
     * resolution still fails. Do not resurrect it.
     *
     * @param  Collection<int, TeamApiKey>  $activeKeys
     */
    private function deployAuthCredentials(CommandExecutor $executor, $activeKeys, ?string $managedKey): void
    {
        // provider-id => key, in OpenClaw's provider vocabulary.
        $providerKeys = [];

        foreach ($activeKeys as $apiKey) {
            $providerId = match ($apiKey->provider) {
                LlmProvider::Anthropic => 'anthropic',
                LlmProvider::OpenAi, LlmProvider::OpenAiCodex => 'openai',
                LlmProvider::OpenRouter => 'openrouter',
                LlmProvider::Bedrock => null,
            };

            if ($providerId !== null) {
                $providerKeys[$providerId] = $apiKey->api_key;
            }
        }

        if ($managedKey && ! isset($providerKeys['openrouter'])) {
            $providerKeys['openrouter'] = $managedKey;
        }

        if ($providerKeys === []) {
            return;
        }

        $agents = $this->server->agents()
            ->whereNotNull('harness_agent_id')
            ->where('harness_type', 'openclaw')
            ->get(['id', 'harness_agent_id', 'auth_provider']);

        foreach ($agents as $agent) {
            foreach ($providerKeys as $providerId => $key) {
                // A subscription agent's provider slot is its OAuth/token
                // profile; injecting an api_key profile could outrank it.
                if ($providerId === 'openai' && $agent->auth_provider === 'chatgpt') {
                    continue;
                }
                if ($providerId === 'anthropic' && $agent->auth_provider === 'claude') {
                    continue;
                }

                $executor->exec(sprintf(
                    'printf %%s\\\\n %s | openclaw models --agent %s auth paste-api-key --provider %s 2>&1 || true',
                    escapeshellarg($key),
                    escapeshellarg($agent->harness_agent_id),
                    escapeshellarg($providerId),
                ));
            }
        }
    }

    /**
     * @param  array<string, string>  $envKeys
     */
    private function updateAgentDefaults(CommandExecutor $executor, OpenClawDefaultsService $defaultsService, array $envKeys): void
    {
        $configPath = '/root/.openclaw/openclaw.json';

        try {
            $config = json_decode($executor->readFile($configPath), true) ?? [];
        } catch (\RuntimeException) {
            $config = [];
        }

        $defaults = $defaultsService->buildDefaults($this->server);
        $config['agents'] = $config['agents'] ?? [];
        $config['agents']['defaults'] = array_replace_recursive(
            $config['agents']['defaults'] ?? [],
            $defaults,
        );

        // Set LLM provider API keys in the env section for model auth
        if (! empty($envKeys)) {
            $config['env'] = array_merge($config['env'] ?? [], $envKeys);
        }

        // Ensure device-pair plugin stays disabled (auto-approve all channel senders)
        $config['plugins'] = $config['plugins'] ?? ['entries' => []];
        if (is_array($config['plugins']) && isset($config['plugins']['entries'])) {
            $config['plugins']['entries']['device-pair'] = ['enabled' => false];
        }

        // Bedrock (BYO-AWS): keep instance-profile discovery enabled for the
        // amazon-bedrock plugin when any agent on the server uses Bedrock.
        $config = $defaultsService->applyBedrockPluginConfig($config, $this->server);

        // When using managed OpenRouter key, prefix model with openrouter/ so
        // OpenClaw routes through OpenRouter instead of calling providers directly
        $team = $this->server->team;
        if ($team->managedApiKey && isset($config['agents']['defaults']['model'])) {
            $model = $config['agents']['defaults']['model'];
            if (is_string($model) && ! str_starts_with($model, 'openrouter/')) {
                $config['agents']['defaults']['model'] = "openrouter/{$model}";
            }
        }

        $executor->writeFile($configPath, OpenClawConfig::toJson($config));
    }
}
