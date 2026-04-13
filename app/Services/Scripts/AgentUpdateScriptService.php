<?php

namespace App\Services\Scripts;

use App\Enums\LlmProvider;
use App\Models\Agent;
use App\Models\Server;
use App\Models\Team;
use App\Services\AgentInstallScriptService;
use App\Services\AgentScheduleService;
use App\Services\ChannelConfigBuilder;
use App\Services\Harness\HermesDriver;
use App\Services\OpenClawDefaultsService;

class AgentUpdateScriptService
{
    public function __construct(
        private ChannelConfigBuilder $configBuilder,
        private OpenClawDefaultsService $defaultsService,
    ) {}

    /**
     * Create an HMAC-signed URL for the agent update script (10-min expiry).
     */
    public function buildSignedUrl(Agent $agent): string
    {
        $expiresAt = now()->addMinutes(10)->timestamp;
        $signature = hash_hmac('sha256', "agent-update|{$agent->id}|{$expiresAt}", config('app.key'));

        return url("/api/agents/{$agent->id}/update-script?expires_at={$expiresAt}&signature={$signature}");
    }

    /**
     * Create an HMAC-signed callback URL for the agent update completion webhook.
     */
    public function buildCallbackUrl(Agent $agent): string
    {
        $expiresAt = now()->addMinutes(30)->timestamp;
        $signature = hash_hmac('sha256', "agent-update-callback|{$agent->id}|{$expiresAt}", config('app.key'));

        return url("/api/webhooks/agent-update?agent_id={$agent->id}&expires_at={$expiresAt}&signature={$signature}");
    }

    /**
     * Build the full target openclaw.json config for an agent's server.
     *
     * Exposed publicly so callers can store the config_snapshot on the agent
     * when dispatching the update script.
     *
     * @return array<string, mixed>
     */
    public function buildOpenClawConfigSnapshot(Agent $agent): array
    {
        $agent->loadMissing([
            'server.team.apiKeys',
            'server.team.managedApiKey',
            'server.agents.slackConnection',
            'server.agents.telegramConnection',
            'server.agents.discordConnection',
            'emailConnection',
        ]);

        return $this->buildFullOpenClawConfig($agent);
    }

    /**
     * Generate the bash update script for an OpenClaw agent.
     *
     * Pre-computes the full openclaw.json config in PHP, eliminating the
     * read-modify-write cycle that required reading the file over SSH first.
     */
    public function generateOpenClawScript(Agent $agent): string
    {
        $agent->loadMissing([
            'server.team.apiKeys',
            'server.team.managedApiKey',
            'server.team.envVars',
            'server.agents.slackConnection',
            'server.agents.telegramConnection',
            'server.agents.discordConnection',
            'slackConnection',
            'telegramConnection',
            'discordConnection',
            'emailConnection',
            'tools',
        ]);

        $server = $agent->server;
        $agentId = $agent->harness_agent_id;
        $agentDir = "/root/.openclaw/agents/{$agentId}";
        $configPath = '/root/.openclaw/openclaw.json';
        $callbackUrl = $this->buildCallbackUrl($agent);

        // Pre-compute the full openclaw.json target config
        $openclawConfig = $this->buildFullOpenClawConfig($agent);
        $configJson = json_encode($openclawConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $lines = [
            '#!/bin/bash',
            'set -e',
            '',
            '# --- OpenClaw Agent Update Script ---',
            "# Agent: {$agent->name} ({$agentId})",
            "# Server: {$server->id}",
            '# Generated: '.now()->toIso8601String(),
            '',
        ];

        // Error trap + callback helpers
        $lines[] = '# --- Error Handling & Callbacks ---';
        $lines[] = 'report_error() {';
        $lines[] = "  curl -sS -X POST '{$callbackUrl}' -d 'status=error&error_message='\"Update failed at line \$1\" || true";
        $lines[] = '  exit 1';
        $lines[] = '}';
        $lines[] = '';
        $lines[] = 'trap \'report_error $LINENO\' ERR';
        $lines[] = '';

        // 1. Write pre-computed openclaw.json
        $lines[] = '# --- Step 1: Write OpenClaw Config ---';
        $lines[] = $this->buildHeredoc($configPath, $configJson);
        $lines[] = '';

        // 2. Create agent directories
        $lines[] = '# --- Step 2: Create Agent Directories ---';
        $lines[] = "mkdir -p {$agentDir}";
        $lines[] = "test -d {$agentDir}/knowledge && ! -d {$agentDir}/workspace && mv {$agentDir}/knowledge {$agentDir}/workspace || true";
        $lines[] = "mkdir -p {$agentDir}/workspace";
        $lines[] = "mkdir -p {$agentDir}/.gh";
        $lines[] = '';

        // 3. Seed .gitconfig if empty or missing
        $lines[] = '# --- Step 3: Git Config (only if empty/missing) ---';
        $gitEmail = $agent->emailConnection?->email_address ?? "{$agentId}@noreply.openclaw.ai";
        $gitConfig = "[user]\n    name = {$agent->name}\n    email = {$gitEmail}";
        $lines[] = "if [ ! -s {$agentDir}/.gitconfig ]; then";
        $lines[] = $this->buildHeredoc("{$agentDir}/.gitconfig", $gitConfig);
        $lines[] = 'fi';
        $lines[] = '';

        // 4. Write workspace markdown files
        $lines[] = '# --- Step 4: Write Workspace Files ---';

        if ($agent->soul) {
            $lines[] = $this->buildHeredoc("{$agentDir}/SOUL.md", $agent->soul);
            $lines[] = '';
        }

        if ($agent->system_prompt) {
            $systemPrompt = $agent->system_prompt;

            // Append delegation instructions for channel agents
            if ($agent->delegation_enabled) {
                $systemPrompt .= "\n\n## Task Delegation\n\n";
                $systemPrompt .= 'You have a `provision-tasks` skill that lets you create and delegate tasks to other agents on your team. ';
                $systemPrompt .= 'When someone asks you to assign, delegate, or create a task for another agent (e.g. "create a task for @max"), ';
                $systemPrompt .= "ALWAYS use the provision-tasks skill — never use the built-in spawn or sub-agent commands.\n\n";
                $systemPrompt .= "To delegate: `node {baseDir}/provision_tasks_tool.js create \"Task title\" --assign \"agent-name\"`\n";
                $systemPrompt .= "To see teammates: `node {baseDir}/provision_tasks_tool.js team-agents`\n";
            }

            $lines[] = $this->buildHeredoc("{$agentDir}/AGENTS.md", $systemPrompt);
            $lines[] = '';
        }

        if ($agent->identity) {
            $identityContent = $agent->identity;

            // Append account credentials (not stored in DB for security)
            $agentEmail = $agent->emailConnection?->email_address ?? '';
            $agentPassword = $agent->default_password ?? '';
            if ($agentEmail && $agentPassword) {
                $identityContent .= "\n\n## Account Credentials\n\n";
                $identityContent .= "When signing up for any service or tool, always use these credentials:\n";
                $identityContent .= "- **Email:** {$agentEmail}\n";
                $identityContent .= "- **Password:** {$agentPassword}\n";
                $identityContent .= "\nNever use any other password. Never share these credentials in chat messages.";
            }

            $lines[] = $this->buildHeredoc("{$agentDir}/IDENTITY.md", $identityContent);
            $lines[] = '';
        }

        if ($agent->user_context) {
            $lines[] = $this->buildHeredoc("{$agentDir}/USER.md", $agent->user_context);
            $lines[] = '';
        }

        // BOOTSTRAP.md: only write if it doesn't exist yet
        $bootstrapContent = AgentInstallScriptService::buildBootstrapContent($agent);
        $lines[] = "if [ ! -f {$agentDir}/BOOTSTRAP.md ]; then";
        $lines[] = $this->buildHeredoc("{$agentDir}/BOOTSTRAP.md", $bootstrapContent);
        $lines[] = 'fi';
        $lines[] = '';

        // HEARTBEAT.md: always overwrite
        $heartbeatContent = AgentInstallScriptService::buildHeartbeatContent($agent->emailConnection);
        $lines[] = $this->buildHeredoc("{$agentDir}/HEARTBEAT.md", $heartbeatContent);
        $lines[] = '';

        // 5. Write TOOLS.md (merged sections)
        $lines[] = '# --- Step 5: Write TOOLS.md & .env ---';
        $toolsMd = $this->buildOpenClawToolsMd($agent);
        if (trim($toolsMd)) {
            $lines[] = $this->buildHeredoc("{$agentDir}/TOOLS.md", trim($toolsMd));
            $lines[] = '';
        }

        // Per-agent .env (with fresh API token)
        $plainToken = AgentInstallScriptService::ensureAgentApiToken($agent);
        $agentEnv = AgentInstallScriptService::buildAgentEnv($agent, $plainToken);
        $lines[] = $this->buildHeredoc("{$agentDir}/.env", $agentEnv);
        $lines[] = '';

        // Write auth-profiles.json for OpenClaw's auth resolver.
        $envKeys = $this->collectLlmProviderEnvKeys($agent->server);
        if (! empty($envKeys['OPENROUTER_API_KEY'])) {
            $key = $envKeys['OPENROUTER_API_KEY'];
            $authProfiles = json_encode([
                'profiles' => [
                    'openrouter:default' => ['provider' => 'openrouter', 'type' => 'api_key', 'key' => $key],
                    'openai-codex:default' => ['provider' => 'openai-codex', 'type' => 'api_key', 'key' => $key],
                ],
                'order' => [
                    'openrouter' => ['openrouter:default'],
                    'openai-codex' => ['openai-codex:default'],
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            $lines[] = '# --- Write auth-profiles.json (OpenRouter + openai-codex) ---';
            $lines[] = "mkdir -p {$agentDir}/agent";
            $lines[] = $this->buildHeredoc("{$agentDir}/agent/auth-profiles.json", $authProfiles);
            $lines[] = 'mkdir -p /root/.openclaw/agents/main/agent';
            $lines[] = $this->buildHeredoc('/root/.openclaw/agents/main/agent/auth-profiles.json', $authProfiles);
            $lines[] = '';
        }

        // Deploy provision-tasks skill (core, always deployed)
        $lines[] = '# --- Deploy provision-tasks skill ---';
        $skillDir = "{$agentDir}/skills/provision-tasks";
        $lines[] = "mkdir -p {$skillDir}";
        $lines[] = $this->buildHeredoc("{$skillDir}/SKILL.md", file_get_contents(resource_path('skills/provision-tasks/SKILL.md')));
        $lines[] = $this->buildHeredoc("{$skillDir}/provision_tasks_tool.js", file_get_contents(resource_path('skills/provision-tasks/provision_tasks_tool.js')));
        $lines[] = $this->buildHeredoc("{$skillDir}/skill.json", file_get_contents(resource_path('skills/provision-tasks/skill.json')));
        $lines[] = '';

        // 6. Deploy MailboxKit skill + email check script (if email connected)
        $emailConnection = $agent->emailConnection;
        if ($emailConnection?->mailboxkit_inbox_id) {
            $lines[] = '# --- Step 6: Email Integration ---';
            $lines[] = $this->buildMailboxKitSkillSection($agent, $agentDir);
            $lines[] = '';
            $lines[] = $this->buildEmailCheckSection($agent, $agentDir);
            $lines[] = '';
        }

        // 7. Restart gateway
        $lines[] = '# --- Step 7: Restart Gateway ---';
        $lines[] = 'export XDG_RUNTIME_DIR=/run/user/$(id -u)';
        $lines[] = 'systemctl --user restart openclaw-gateway';
        $lines[] = 'sleep 5';
        $lines[] = '';

        // 8. Health check + callback
        $lines[] = '# --- Step 8: Health Check & Callback ---';
        $configSnapshotJson = json_encode($openclawConfig, JSON_UNESCAPED_SLASHES);
        // URL-encode the config snapshot for POST data
        $lines[] = 'HEALTHY=0';
        $lines[] = 'if openclaw health 2>/dev/null || (sleep 5 && openclaw health 2>/dev/null); then';
        $lines[] = '  HEALTHY=1';
        $lines[] = 'fi';
        $lines[] = '';

        // Start the managed browser service after gateway restart
        $lines[] = 'openclaw browser --browser-profile openclaw start 2>&1 || true';
        $lines[] = '';

        $lines[] = 'if [ "$HEALTHY" -eq 1 ]; then';
        $lines[] = "  curl -sS -X POST '{$callbackUrl}' -d 'status=updated' || true";
        $lines[] = 'else';
        $lines[] = "  curl -sS -X POST '{$callbackUrl}' -d 'status=updated&warning=health_check_failed' || true";
        $lines[] = 'fi';

        return implode("\n", $lines)."\n";
    }

    /**
     * Generate the bash update script for a Hermes agent.
     *
     * Preserves the existing BROWSER_CDP_URL from .env using inline bash logic.
     */
    public function generateHermesScript(Agent $agent): string
    {
        $agent->loadMissing([
            'server.team.apiKeys',
            'server.team.managedApiKey',
            'server.team.envVars',
            'slackConnection',
            'telegramConnection',
            'discordConnection',
            'emailConnection',
            'tools',
            'team.owner',
        ]);

        $hermesDriver = app(HermesDriver::class);
        $hermesHome = $hermesDriver->agentDir($agent);
        $agentId = $agent->harness_agent_id;
        $callbackUrl = $this->buildCallbackUrl($agent);

        $lines = [
            '#!/bin/bash',
            'set -e',
            '',
            '# --- Hermes Agent Update Script ---',
            "# Agent: {$agent->name} ({$agentId})",
            "# Home: {$hermesHome}",
            '# Generated: '.now()->toIso8601String(),
            '',
        ];

        // Error trap
        $lines[] = '# --- Error Handling ---';
        $lines[] = 'report_error() {';
        $lines[] = "  curl -sS -X POST '{$callbackUrl}' -d 'status=error&error_message='\"Update failed at line \$1\" || true";
        $lines[] = '  exit 1';
        $lines[] = '}';
        $lines[] = '';
        $lines[] = 'trap \'report_error $LINENO\' ERR';
        $lines[] = '';

        // 1. Write workspace files (SOUL.md, USER.md, MEMORY.md, AGENTS.md)
        $lines[] = '# --- Step 1: Write Workspace Files ---';
        $lines[] = "mkdir -p {$hermesHome}/memories {$hermesHome}/workspace {$hermesHome}/.gh {$hermesHome}/skills";
        $lines[] = 'mkdir -p /mnt/provision-shared';
        $lines[] = "ln -sfn /mnt/provision-shared {$hermesHome}/workspace/shared";
        $lines[] = '';

        $soulContent = $this->buildHermesSoulContent($agent);
        $lines[] = $this->buildHeredoc("{$hermesHome}/SOUL.md", $soulContent);
        $lines[] = '';

        $userMd = $this->buildHermesUserMd($agent);
        $lines[] = $this->buildHeredoc("{$hermesHome}/memories/USER.md", $userMd);
        $lines[] = '';

        $memoryMd = $this->buildHermesMemoryMd($agent, $hermesHome);
        $lines[] = $this->buildHeredoc("{$hermesHome}/memories/MEMORY.md", $memoryMd);
        $lines[] = '';

        $agentsMd = $this->buildHermesAgentsMd($agent, $hermesHome);
        if (trim($agentsMd)) {
            $lines[] = $this->buildHeredoc("{$hermesHome}/AGENTS.md", trim($agentsMd));
            $lines[] = '';
        }

        // 2. Write config.yaml
        $lines[] = '# --- Step 2: Write Config ---';
        $configYaml = $this->buildHermesConfigYaml($agent, $hermesHome);
        $lines[] = $this->buildHeredoc("{$hermesHome}/config.yaml", $configYaml);
        $lines[] = '';

        // 3. Write .env (preserve BROWSER_CDP_URL)
        $lines[] = '# --- Step 3: Write .env (preserving BROWSER_CDP_URL) ---';
        $lines[] = "CDP_URL=\$(grep '^BROWSER_CDP_URL=' {$hermesHome}/.env 2>/dev/null || true)";
        $envContent = $this->buildHermesFullEnv($agent, $hermesHome);
        $lines[] = $this->buildHeredoc("{$hermesHome}/.env", $envContent);
        $lines[] = '[ -n "$CDP_URL" ] && echo "$CDP_URL" >> '.$hermesHome.'/.env';
        $lines[] = '';

        // 4. Git config (only if empty/missing)
        $lines[] = '# --- Step 4: Git Config ---';
        $gitEmail = $agent->emailConnection?->email_address ?? "{$agentId}@noreply.openclaw.ai";
        $gitConfig = "[user]\n    name = {$agent->name}\n    email = {$gitEmail}";
        $lines[] = "if [ ! -s {$hermesHome}/.gitconfig ]; then";
        $lines[] = $this->buildHeredoc("{$hermesHome}/.gitconfig", $gitConfig);
        $lines[] = 'fi';
        $lines[] = '';

        // 5. Email integration (if connected)
        if ($agent->emailConnection?->mailboxkit_inbox_id) {
            $lines[] = '# --- Step 5: Email Integration ---';
            $lines[] = $this->buildMailboxKitSkillSection($agent, $hermesHome);
            $lines[] = '';
            $lines[] = $this->buildEmailCheckSection($agent, $hermesHome);
            $lines[] = '';
        }

        // 6. Restart Hermes gateway
        $lines[] = '# --- Step 6: Restart Gateway ---';
        $lines[] = "export HERMES_HOME={$hermesHome} XDG_RUNTIME_DIR=/run/user/\$(id -u)";
        $lines[] = '/root/.local/bin/hermes gateway restart 2>&1 || true';
        $lines[] = 'sleep 3';
        $lines[] = '';

        // 7. Health check + callback
        $lines[] = '# --- Step 7: Health Check & Callback ---';
        $lines[] = "HEALTH_OUTPUT=\$(HERMES_HOME={$hermesHome} XDG_RUNTIME_DIR=/run/user/\$(id -u) /root/.local/bin/hermes gateway status 2>&1 || true)";
        $lines[] = 'HEALTHY=0';
        $lines[] = 'if echo "$HEALTH_OUTPUT" | grep -qE "running|active"; then';
        $lines[] = '  HEALTHY=1';
        $lines[] = 'else';
        $lines[] = '  sleep 10';
        $lines[] = "  HEALTH_OUTPUT=\$(HERMES_HOME={$hermesHome} XDG_RUNTIME_DIR=/run/user/\$(id -u) /root/.local/bin/hermes gateway status 2>&1 || true)";
        $lines[] = '  if echo "$HEALTH_OUTPUT" | grep -qE "running|active"; then';
        $lines[] = '    HEALTHY=1';
        $lines[] = '  fi';
        $lines[] = 'fi';
        $lines[] = '';
        $lines[] = 'if [ "$HEALTHY" -eq 1 ]; then';
        $lines[] = "  curl -sS -X POST '{$callbackUrl}' -d 'status=updated' || true";
        $lines[] = 'else';
        $lines[] = "  curl -sS -X POST '{$callbackUrl}' -d 'status=updated&warning=health_check_failed' || true";
        $lines[] = 'fi';

        return implode("\n", $lines)."\n";
    }

    // =========================================================================
    // OpenClaw config builder
    // =========================================================================

    /**
     * Build the full target openclaw.json config for an agent update.
     *
     * Loads all agents on the server from the DB, builds the complete config
     * including all channel accounts/bindings, so no read-modify-write is needed.
     *
     * @return array<string, mixed>
     */
    private function buildFullOpenClawConfig(Agent $agent): array
    {
        $server = $agent->server;

        // Start from the server setup defaults
        $config = $this->buildBaseOpenClawConfig($server);

        // Build agent list from all agents on this server
        $allAgents = $server->agents()
            ->where('harness_type', 'openclaw')
            ->whereNotNull('harness_agent_id')
            ->with(['slackConnection', 'telegramConnection', 'discordConnection', 'emailConnection'])
            ->get();

        $agentList = [];
        foreach ($allAgents as $serverAgent) {
            $agentDir = "/root/.openclaw/agents/{$serverAgent->harness_agent_id}";
            $agentList[] = [
                'id' => $serverAgent->harness_agent_id,
                'name' => $serverAgent->name,
                'workspace' => $agentDir,
                'agentDir' => "{$agentDir}/agent",
                'model' => $serverAgent->openclawModelConfig(),
            ];
        }

        $config['agents']['list'] = $agentList;

        // Heartbeat defaults: cheap model, light context
        $config['agents']['defaults'] = $config['agents']['defaults'] ?? [];
        $config['agents']['defaults']['heartbeat'] = $config['agents']['defaults']['heartbeat'] ?? [];
        $config['agents']['defaults']['heartbeat']['model'] = LlmProvider::AUTOMATION_MODEL;
        $config['agents']['defaults']['heartbeat']['lightContext'] = true;

        // Rebuild all channel configs from database
        $this->configBuilder->applyToConfig($config, $server);

        // Add MailboxKit skill config for any agent with email
        $hasEmailAgent = $allAgents->contains(fn ($a) => $a->emailConnection?->mailboxkit_inbox_id);
        if ($hasEmailAgent) {
            $config['skills'] = $config['skills'] ?? [];
            $config['skills']['entries'] = $config['skills']['entries'] ?? [];
            $config['skills']['entries']['mailboxkit'] = ['enabled' => true];
        }

        // Clean up legacy/invalid keys
        foreach (['MAILBOXKIT_API_KEY', 'MAILBOXKIT_INBOX_ID', 'MAILBOXKIT_EMAIL', 'GH_CONFIG_DIR', 'GIT_CONFIG_GLOBAL', 'PROVISION_API_URL', 'PROVISION_AGENT_TOKEN'] as $key) {
            unset($config['env'][$key]);
        }
        unset($config['config']);
        unset($config['tools']['profile']);

        // Enable provision-tasks skill (core, always deployed)
        $config['skills'] = $config['skills'] ?? [];
        $config['skills']['entries'] = $config['skills']['entries'] ?? [];
        $config['skills']['entries']['provision-tasks'] = ['enabled' => true];

        return $config;
    }

    /**
     * Build the base openclaw.json config (mirrors ServerSetupScriptService structure).
     *
     * @return array<string, mixed>
     */
    private function buildBaseOpenClawConfig(Server $server): array
    {
        $server->loadMissing('team.apiKeys');
        $defaults = $this->defaultsService->buildDefaults($server);

        $config = [];

        $config['bindings'] = [];

        $config['agents'] = [
            'defaults' => array_replace_recursive([
                'sandbox' => ['mode' => 'off'],
                'typingMode' => 'thinking',
                'heartbeat' => ['lightContext' => true],
            ], $defaults),
        ];

        $config['browser'] = [
            'enabled' => true,
            'headless' => false,
            'noSandbox' => true,
            'executablePath' => config('openclaw.browser_executable_path'),
            'snapshotDefaults' => ['mode' => 'efficient'],
            'extraArgs' => ['--ozone-override-screen-size=1440,900', '--window-size=1440,900'],
        ];

        $config['channels'] = [];

        $config['gateway'] = [
            'mode' => 'local',
            'bind' => config('openclaw.gateway_bind'),
            'http' => [
                'endpoints' => [
                    'chatCompletions' => ['enabled' => true],
                    'responses' => ['enabled' => true],
                ],
            ],
        ];

        $config['logging'] = [
            'redactSensitive' => 'tools',
        ];

        $config['messages'] = [
            'queue' => [
                'mode' => 'collect',
                'debounceMs' => 2000,
            ],
            'inbound' => [
                'debounceMs' => 2000,
            ],
        ];

        $config['plugins'] = ['entries' => []];

        $config['session'] = [
            'dmScope' => 'per-channel-peer',
            'reset' => [
                'mode' => 'idle',
                'idleMinutes' => 120,
            ],
            'maintenance' => [
                'pruneAfter' => '14d',
                'maxEntries' => 500,
                'maxDiskBytes' => '500mb',
            ],
        ];

        $config['skills'] = [
            'load' => ['watch' => true],
        ];

        $config['tools'] = [
            'deny' => ['canvas', 'nodes', 'gateway', 'config', 'system', 'telegram', 'whatsapp', 'discord', 'irc', 'googlechat', 'slack', 'signal', 'imessage'],
            'loopDetection' => [
                'enabled' => true,
                'historySize' => 30,
                'warningThreshold' => 10,
                'criticalThreshold' => 20,
            ],
        ];

        // LLM provider env keys (shared across all agents on this server)
        $envKeys = $this->collectLlmProviderEnvKeys($server);
        if (! empty($envKeys)) {
            $config['env'] = $envKeys;
        }

        return $config;
    }

    /**
     * Collect LLM provider API keys for the server's team.
     *
     * @return array<string, string>
     */
    private function collectLlmProviderEnvKeys(Server $server): array
    {
        $team = $server->team;
        $activeKeys = $team->apiKeys()->where('is_active', true)->get();
        $envKeys = [];

        foreach ($activeKeys as $apiKey) {
            $envKeys[$apiKey->provider->envKeyName()] = $apiKey->api_key;
        }

        // If team has OpenRouter but no native OpenAI key, alias it for embedding auth
        $hasOpenAi = $activeKeys->contains('provider', LlmProvider::OpenAi);
        $openRouterKey = $activeKeys->firstWhere('provider', LlmProvider::OpenRouter);

        if (! $hasOpenAi && $openRouterKey) {
            $envKeys['OPENAI_API_KEY'] = $openRouterKey->api_key;
        }

        // Add managed API key if no user-provided OpenRouter key exists
        $managedKey = $team->managedApiKey;
        $hasAnthropic = $activeKeys->contains('provider', LlmProvider::Anthropic);
        if ($managedKey && ! $activeKeys->contains('provider', LlmProvider::OpenRouter)) {
            $envKeys['OPENROUTER_API_KEY'] = $managedKey->api_key;

            if (! $hasOpenAi) {
                $envKeys['OPENAI_API_KEY'] = $managedKey->api_key;
            }

            if (! $hasAnthropic) {
                $envKeys['ANTHROPIC_API_KEY'] = $managedKey->api_key;
            }
        }

        return $envKeys;
    }

    // =========================================================================
    // OpenClaw TOOLS.md builder
    // =========================================================================

    private function buildOpenClawToolsMd(Agent $agent): string
    {
        $toolsMd = $agent->tools_config ?? '';

        if ($agent->emailConnection?->mailboxkit_inbox_id) {
            $toolsMd .= "\n\n".AgentInstallScriptService::emailToolsMd($agent->emailConnection);
        }

        $toolsMd .= "\n\n".AgentInstallScriptService::workspaceToolsMd();
        $toolsMd .= "\n\n".AgentInstallScriptService::gitToolsMd();
        $toolsMd .= "\n\n".AgentInstallScriptService::browserToolsMd($agent);

        return $toolsMd;
    }

    // =========================================================================
    // Hermes content builders
    // =========================================================================

    private function buildHermesSoulContent(Agent $agent): string
    {
        $parts = [];

        if ($agent->soul) {
            $parts[] = $agent->soul;
        }

        if ($agent->identity) {
            $parts[] = $agent->identity;
        }

        return implode("\n\n", array_filter($parts)) ?: "You are {$agent->name}, a helpful AI assistant.";
    }

    private function buildHermesUserMd(Agent $agent): string
    {
        $agent->loadMissing('team.owner');
        $owner = $agent->team?->owner;

        $lines = [];

        if ($owner) {
            $lines[] = "Name: {$owner->name}";
            $lines[] = "Email: {$owner->email}";
        }

        if ($owner?->timezone) {
            $lines[] = "Timezone: {$owner->timezone}";
        }

        if ($agent->user_context) {
            $lines[] = '';
            $lines[] = $agent->user_context;
        }

        return implode("\n", $lines);
    }

    private function buildHermesMemoryMd(Agent $agent, string $hermesHome): string
    {
        $lines = [];

        $lines[] = "My name is {$agent->name}.";

        $agentEmail = $agent->emailConnection?->email_address;
        if ($agentEmail) {
            $lines[] = "My email address is {$agentEmail}.";
            $lines[] = 'I ALWAYS use the MailboxKit API (curl + exec) for ALL email — sending, receiving, replying. Never use himalaya, sendmail, or any other email tool. MailboxKit is my only email system. See the mailboxkit skill for curl commands.';
        }

        $lines[] = "My workspace is at {$hermesHome}/workspace/.";
        $lines[] = 'I have my own isolated browser (real Chrome, not headless) connected via CDP.';
        $lines[] = 'I have isolated Git credentials — my commits use my name and email.';

        return implode("\n§\n", $lines);
    }

    private function buildHermesAgentsMd(Agent $agent, string $hermesHome): string
    {
        $agentsMd = $agent->system_prompt ?? '';

        $toolsMd = $agent->tools_config ?? '';

        if ($agent->emailConnection?->mailboxkit_inbox_id) {
            $toolsMd .= "\n\n".AgentInstallScriptService::emailToolsMd($agent->emailConnection);
        }

        $toolsMd .= "\n\n".$this->buildHermesBrowserToolsMd();
        $toolsMd .= "\n\n".$this->buildHermesGitToolsMd();
        $toolsMd .= "\n\n".$this->buildHermesWorkspaceToolsMd($hermesHome);

        if (trim($toolsMd)) {
            $agentsMd .= "\n\n# Tools & Capabilities\n\n".trim($toolsMd);
        }

        return $agentsMd;
    }

    private function buildHermesConfigYaml(Agent $agent, string $hermesHome): string
    {
        $hermesDriver = app(HermesDriver::class);
        $model = $hermesDriver->formatModelConfig($agent);
        $modelStr = is_array($model) ? $model['primary'] : $model;
        $timezone = $agent->server?->team?->timezone ?? 'UTC';

        return implode("\n", [
            '# Hermes Agent config — managed by Provision',
            "model: \"{$modelStr}\"",
            '',
            "timezone: \"{$timezone}\"",
            '',
            'terminal:',
            '  backend: local',
            "  cwd: \"{$hermesHome}/workspace\"",
            '  timeout: 180',
            '  persistent_shell: true',
            '',
            'memory:',
            '  memory_enabled: true',
            '  user_profile_enabled: true',
            '  memory_char_limit: 2200',
            '  user_char_limit: 1375',
            '',
            'compression:',
            '  enabled: true',
            '  threshold: 0.50',
            '  target_ratio: 0.20',
            '  protect_last_n: 20',
            '',
            'agent:',
            '  max_turns: 120',
            '  reasoning_effort: ""',
            '',
            'approvals:',
            '  mode: smart',
            '',
            'skills:',
            '  agent_managed: true',
            '  deny_list: himalaya',
            '',
            'display:',
            '  tool_progress: new',
            '  tool_progress_command: false',
            '  bell_on_complete: false',
            '',
            'browser:',
            '  inactivity_timeout: 120',
            '  command_timeout: 30',
            '',
            'group_sessions_per_user: true',
            '',
            'checkpoints:',
            '  enabled: true',
            '  max_snapshots: 50',
            '',
            'privacy:',
            '  redact_pii: false',
            '',
            'security:',
            '  redact_secrets: true',
            '',
        ]);
    }

    /**
     * Build the complete .env content for a Hermes agent (base + channel tokens).
     *
     * Does NOT include BROWSER_CDP_URL — that is preserved from the existing .env
     * by the bash script itself.
     */
    private function buildHermesFullEnv(Agent $agent, string $hermesHome): string
    {
        $lines = [];

        // Allow all users (no pairing required)
        $lines[] = 'GATEWAY_ALLOW_ALL_USERS=true';

        // Git/GitHub credential isolation
        $lines[] = "GH_CONFIG_DIR={$hermesHome}/.gh";
        $lines[] = "GIT_CONFIG_GLOBAL={$hermesHome}/.gitconfig";

        // LLM provider API keys from team
        $apiKeys = $agent->server?->team?->apiKeys ?? collect();

        foreach ($apiKeys as $key) {
            if (! $key->is_active) {
                continue;
            }

            $envVar = match ($key->provider->value) {
                'anthropic' => 'ANTHROPIC_API_KEY',
                'openai' => 'OPENAI_API_KEY',
                'open_router' => 'OPENROUTER_API_KEY',
                default => null,
            };

            if ($envVar) {
                $lines[] = "{$envVar}={$key->decrypted_key}";
            }
        }

        // Add managed OpenRouter key if no user-provided one exists
        $hasOpenRouter = collect($lines)->contains(fn ($l) => str_starts_with($l, 'OPENROUTER_API_KEY='));
        $hasAnthropic = collect($lines)->contains(fn ($l) => str_starts_with($l, 'ANTHROPIC_API_KEY='));
        $managedKey = $agent->server?->team?->managedApiKey;
        if (! $hasOpenRouter && $managedKey) {
            $lines[] = "OPENROUTER_API_KEY={$managedKey->api_key}";

            if (! $hasAnthropic) {
                $lines[] = "ANTHROPIC_API_KEY={$managedKey->api_key}";
            }
        }

        // Firecrawl API key for web search
        $firecrawlKey = config('services.firecrawl.api_key');
        if ($firecrawlKey) {
            $lines[] = "FIRECRAWL_API_KEY={$firecrawlKey}";
        }

        // MailboxKit credentials if email connected
        if ($agent->emailConnection?->mailboxkit_inbox_id) {
            $lines[] = 'MAILBOXKIT_API_KEY='.config('mailboxkit.api_key');
            $lines[] = "MAILBOXKIT_INBOX_ID={$agent->emailConnection->mailboxkit_inbox_id}";
            $lines[] = "MAILBOXKIT_EMAIL={$agent->emailConnection->email_address}";
        }

        // Channel tokens
        if ($agent->telegramConnection?->bot_token) {
            $lines[] = "TELEGRAM_BOT_TOKEN={$agent->telegramConnection->bot_token}";
        }

        if ($agent->slackConnection?->bot_token && $agent->slackConnection?->app_token) {
            $lines[] = "SLACK_BOT_TOKEN={$agent->slackConnection->bot_token}";
            $lines[] = "SLACK_APP_TOKEN={$agent->slackConnection->app_token}";
        }

        if ($agent->discordConnection?->token) {
            $lines[] = "DISCORD_BOT_TOKEN={$agent->discordConnection->token}";
            if ($agent->discordConnection->guild_id) {
                $lines[] = "DISCORD_GUILD_ID={$agent->discordConnection->guild_id}";
            }
        }

        // Team environment variables (custom API keys, secrets, config)
        foreach ($agent->server?->team?->envVars ?? [] as $envVar) {
            $lines[] = "{$envVar->key}={$envVar->value}";
        }

        // Hermes API server — each agent gets its own on a unique port
        $apiServerKey = bin2hex(random_bytes(24));
        $apiServerPort = $this->resolveHermesApiServerPort($agent);
        $lines[] = 'API_SERVER_ENABLED=true';
        $lines[] = 'API_SERVER_HOST=0.0.0.0';
        $lines[] = "API_SERVER_PORT={$apiServerPort}";
        $lines[] = "API_SERVER_KEY={$apiServerKey}";

        return implode("\n", $lines);
    }

    private function resolveHermesApiServerPort(Agent $agent): int
    {
        if ($agent->api_server_port) {
            return $agent->api_server_port;
        }

        $basePort = 8642;
        $existingCount = $agent->server
            ? $agent->server->agents()->whereNotNull('api_server_port')->count()
            : 0;

        $port = $basePort + $existingCount;
        $agent->update(['api_server_port' => $port]);

        return $port;
    }

    // =========================================================================
    // Hermes TOOLS.md section builders
    // =========================================================================

    private function buildHermesBrowserToolsMd(): string
    {
        return implode("\n", [
            '## Browser',
            '',
            'You have your own isolated browser with a real Chrome instance (not headless).',
            'Your browser has separate cookies, sessions, and login state from other agents.',
            'The browser is connected via CDP — use `/browser connect` if prompted.',
            '',
            'If you encounter a CAPTCHA or human verification that you cannot solve, tell your team member:',
            '"I need help with a verification step. Please check the Browser tab on my dashboard to take over."',
        ]);
    }

    private function buildHermesGitToolsMd(): string
    {
        return implode("\n", [
            '## Git & GitHub',
            '',
            'You have isolated Git and GitHub credentials. Your commits will use your name and email.',
            'Run `gh auth status` to check if GitHub is authenticated.',
            'If not authenticated, use `gh auth login` with your credentials.',
            '',
            'Never modify other agents\' git config or GitHub credentials.',
        ]);
    }

    private function buildHermesWorkspaceToolsMd(string $hermesHome): string
    {
        return implode("\n", [
            '## Workspace',
            '',
            "Your workspace is at `{$hermesHome}/workspace/`.",
            'Save all work output, files, and artifacts here.',
            'This directory persists across sessions.',
        ]);
    }

    // =========================================================================
    // Shared helpers
    // =========================================================================

    /**
     * Build the MailboxKit skill deployment bash section.
     */
    private function buildMailboxKitSkillSection(Agent $agent, string $baseDir): string
    {
        $emailConnection = $agent->emailConnection;

        $skillContent = file_get_contents(resource_path('skills/mailboxkit/SKILL.md'));
        $skillContent = str_replace('$MAILBOXKIT_API_KEY', config('mailboxkit.api_key'), $skillContent);
        $skillContent = str_replace('$MAILBOXKIT_INBOX_ID', (string) $emailConnection->mailboxkit_inbox_id, $skillContent);
        $skillContent = str_replace('$MAILBOXKIT_EMAIL', $emailConnection->email_address, $skillContent);

        $skillDir = "{$baseDir}/skills/mailboxkit";

        $lines = [];
        $lines[] = "mkdir -p {$skillDir}";
        $lines[] = $this->buildHeredoc("{$skillDir}/SKILL.md", $skillContent);

        return implode("\n", $lines);
    }

    /**
     * Build the email check script deployment + crontab bash section.
     */
    private function buildEmailCheckSection(Agent $agent, string $baseDir): string
    {
        $agentId = $agent->harness_agent_id;

        $script = AgentScheduleService::buildEmailCheckScript($agentId, $baseDir);
        $script = str_replace(['__AGENT_DIR__', '__AGENT_ID__'], [$baseDir, $agentId], $script);

        $marker = "# provision-email-check-{$agentId}";
        $cronLine = "*/5 * * * * {$baseDir}/check-email.sh >> {$baseDir}/email-check.log 2>&1 {$marker}";

        $lines = [];
        $lines[] = $this->buildHeredoc("{$baseDir}/check-email.sh", $script);
        $lines[] = "chmod +x {$baseDir}/check-email.sh";
        $lines[] = "(crontab -l 2>/dev/null | grep -v '{$marker}'; echo '{$cronLine}') | crontab -";

        return implode("\n", $lines);
    }

    /**
     * Build a heredoc block that writes content to a file path.
     * Uses single-quoted delimiter to prevent shell variable expansion.
     */
    private function buildHeredoc(string $filePath, string $content): string
    {
        return "cat > {$filePath} << 'HEREDOC_EOF'\n{$content}\nHEREDOC_EOF";
    }
}
