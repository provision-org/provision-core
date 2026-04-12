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
     * Register the OpenRouter API key with OpenClaw's auth system.
     *
     * Uses `openclaw models auth paste-token` to write auth-profiles.json
     * in the correct format. This is the official OpenClaw CLI method for
     * setting up provider credentials on headless servers.
     *
     * @param  array<string, string>  $envKeys
     */
    private function deployAuthProfiles(CommandExecutor $executor, array $envKeys): void
    {
        $openRouterKey = $envKeys['OPENROUTER_API_KEY'] ?? null;

        if (! $openRouterKey) {
            return;
        }

        // Use OpenClaw's own CLI to register the API key in the correct format.
        // paste-token reads from stdin, writes to auth-profiles.json, and updates
        // the gateway config — all in the format OpenClaw expects.
        $executor->exec(
            "echo '{$openRouterKey}' | openclaw models auth paste-token --provider openrouter --profile-id openrouter:default 2>&1 || true"
        );
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
