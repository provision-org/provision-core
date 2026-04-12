<?php

namespace App\Jobs;

use App\Contracts\CommandExecutor;
use App\Enums\LlmProvider;
use App\Models\Server;
use App\Services\HarnessManager;
use App\Services\OpenClawDefaultsService;
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
     * Deploy auth-profiles.json to each agent's directory on the server.
     *
     * OpenClaw resolves API keys from {agentDir}/agent/auth-profiles.json.
     * Each provider entry contains the mode and plaintext key.
     *
     * @param  array<string, string>  $envKeys
     */
    private function deployAuthProfiles(CommandExecutor $executor, array $envKeys): void
    {
        if (empty($envKeys)) {
            return;
        }

        // Build the auth profiles object — map env key names to OpenClaw provider IDs
        $profiles = [];
        $order = [];

        if (! empty($envKeys['OPENROUTER_API_KEY'])) {
            $key = $envKeys['OPENROUTER_API_KEY'];
            $profiles['openrouter:default'] = [
                'provider' => 'openrouter',
                'mode' => 'api_key',
                'key' => $key,
            ];
            $order['openrouter'] = ['openrouter:default'];

            // OpenClaw's context engine and tools use openai-codex provider.
            // Route it through OpenRouter by providing the same key.
            $profiles['openai-codex:default'] = [
                'provider' => 'openai-codex',
                'mode' => 'api_key',
                'key' => $key,
            ];
            $order['openai-codex'] = ['openai-codex:default'];
        }

        if (! empty($envKeys['OPENAI_API_KEY']) && empty($profiles['openai-codex:default'])) {
            $profiles['openai-codex:default'] = [
                'provider' => 'openai-codex',
                'mode' => 'api_key',
                'key' => $envKeys['OPENAI_API_KEY'],
            ];
            $order['openai-codex'] = ['openai-codex:default'];
        }

        if (! empty($envKeys['ANTHROPIC_API_KEY'])) {
            $profiles['anthropic:default'] = [
                'provider' => 'anthropic',
                'mode' => 'api_key',
                'key' => $envKeys['ANTHROPIC_API_KEY'],
            ];
            $order['anthropic'] = ['anthropic:default'];
        }

        if (empty($profiles)) {
            return;
        }

        $authProfilesJson = json_encode($profiles, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        // Write auth-profiles.json to every agent directory on this server
        $agents = $this->server->agents()
            ->whereNotNull('harness_agent_id')
            ->where('harness_type', 'openclaw')
            ->pluck('harness_agent_id');

        foreach ($agents as $agentId) {
            $agentDir = "/root/.openclaw/agents/{$agentId}/agent";
            $executor->exec("mkdir -p {$agentDir}");
            $executor->writeFile("{$agentDir}/auth-profiles.json", $authProfilesJson);
        }

        // Also update openclaw.json auth section so the gateway knows which profiles exist
        $configPath = '/root/.openclaw/openclaw.json';

        try {
            $config = json_decode($executor->readFile($configPath), true) ?? [];
        } catch (\RuntimeException) {
            $config = [];
        }

        // Declare the auth profiles and ordering in the gateway config
        // (the actual keys are in per-agent auth-profiles.json files)
        $configProfiles = [];
        foreach ($profiles as $id => $profile) {
            $configProfiles[$id] = [
                'provider' => $profile['provider'],
                'mode' => $profile['mode'],
            ];
        }

        $config['auth'] = [
            'profiles' => $configProfiles,
            'order' => $order,
        ];

        $executor->writeFile($configPath, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
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

        // Preserve empty objects — PHP json_decode converts {} to [], but OpenClaw expects objects
        foreach (['channels', 'plugins'] as $key) {
            if (isset($config[$key]) && $config[$key] === []) {
                $config[$key] = (object) [];
            }
        }

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

        $executor->writeFile($configPath, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
