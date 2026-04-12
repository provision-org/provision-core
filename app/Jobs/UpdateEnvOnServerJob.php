<?php

namespace App\Jobs;

use App\Contracts\CommandExecutor;
use App\Enums\LlmProvider;
use App\Models\Server;
use App\Services\HarnessManager;
use App\Services\OpenClawDefaultsService;
use App\Support\OpenClawConfig;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class UpdateEnvOnServerJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public Server $server) {}

    public function handle(HarnessManager $harnessManager, OpenClawDefaultsService $defaultsService): void
    {
        $team = $this->server->team;
        $activeKeys = $team->apiKeys()->where('is_active', true)->get();
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

        // Write auth-profiles.json for each agent so OpenClaw's auth resolver
        // can find API keys for all providers (openrouter, openai-codex, anthropic).
        // OpenClaw v2026.4+ resolves keys from {agentDir}/agent/auth-profiles.json,
        // not from the openclaw.json env block.
        $this->deployAuthProfiles($executor, $envConfigKeys);

        RestartGatewayJob::dispatch($this->server);
    }

    /**
     * Deploy auth-profiles.json to each agent's directory and the default 'main' agent path.
     *
     * OpenClaw resolves API keys from {agentDir}/agent/auth-profiles.json.
     * Due to OpenClaw bug #24016, the 'main' agent path is also checked even
     * when no 'main' agent exists. We write to all paths to ensure coverage.
     *
     * @param  array<string, string>  $envKeys
     */
    private function deployAuthProfiles(CommandExecutor $executor, array $envKeys): void
    {
        $openRouterKey = $envKeys['OPENROUTER_API_KEY'] ?? null;

        if (! $openRouterKey) {
            return;
        }

        // Build auth-profiles with both openrouter and openai-codex providers.
        // openai-codex is needed because OpenClaw's internal systems (compaction,
        // hooks, crons) use it as a fallback even when the model is openrouter/*.
        $authProfiles = json_encode([
            'profiles' => [
                'openrouter:default' => [
                    'provider' => 'openrouter',
                    'type' => 'api_key',
                    'key' => $openRouterKey,
                ],
                'openai-codex:default' => [
                    'provider' => 'openai-codex',
                    'type' => 'api_key',
                    'key' => $openRouterKey,
                ],
            ],
            'order' => [
                'openrouter' => ['openrouter:default'],
                'openai-codex' => ['openai-codex:default'],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        // Write to every agent directory on this server
        $agents = $this->server->agents()
            ->whereNotNull('harness_agent_id')
            ->where('harness_type', 'openclaw')
            ->pluck('harness_agent_id');

        foreach ($agents as $agentId) {
            $agentDir = "/root/.openclaw/agents/{$agentId}/agent";
            $executor->exec("mkdir -p {$agentDir}");
            $executor->writeFile("{$agentDir}/auth-profiles.json", $authProfiles);
        }

        // Also write to the 'main' agent path (OpenClaw bug #24016 workaround)
        $executor->exec('mkdir -p /root/.openclaw/agents/main/agent');
        $executor->writeFile('/root/.openclaw/agents/main/agent/auth-profiles.json', $authProfiles);
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
