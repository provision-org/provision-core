<?php

namespace App\Services\Harness;

use App\Contracts\CommandExecutor;
use App\Contracts\HarnessDriver;
use App\Contracts\Modules\AgentProxyProvider;
use App\Enums\AgentStatus;
use App\Enums\LlmProvider;
use App\Events\AgentUpdatedEvent;
use App\Models\Agent;
use App\Models\Server;
use App\Models\Team;
use App\Services\AgentInstallScriptService;
use App\Services\AgentScheduleService;
use App\Services\Scripts\AgentUpdateScriptService;
use App\Services\Scripts\HermesInstallScriptService;
use Illuminate\Support\Facades\Log;

class HermesDriver implements HarnessDriver
{
    public function setupOnServer(Server $server, CommandExecutor $executor): void
    {
        try {
            $executor->exec('command -v /root/.local/bin/hermes || (curl -fsSL https://raw.githubusercontent.com/NousResearch/hermes-agent/main/scripts/install.sh | bash)');
            Log::info("Hermes verified on server {$server->id}");
        } catch (\RuntimeException $e) {
            Log::warning("Hermes setup failed on server {$server->id}: {$e->getMessage()}");
            throw $e;
        }
    }

    public function createAgent(Agent $agent, CommandExecutor $executor): void
    {
        // For Docker, generate script directly (avoids HTTP self-callback deadlock).
        // For SSH, use signed URL (server downloads + executes).
        $scriptService = app(HermesInstallScriptService::class);
        if ($agent->server?->isDocker()) {
            $script = $scriptService->generateScript($agent);
            $executor->execScript($script);
        } else {
            $scriptUrl = $scriptService->buildSignedUrl($agent);
            $executor->execScript($scriptUrl);
        }

        // The script handles all setup: dir creation, hermes init, workspace files,
        // config, channels, browser display, git, email, ByteRover, gateway install+start.
        // The callback sets the agent to Active.

        // Verify health as fallback (in case callback didn't fire)
        sleep(5);
        $healthy = $this->checkHealth($agent, $executor);

        if (! $healthy) {
            sleep(10);
            $healthy = $this->checkHealth($agent, $executor);
        }

        $agent->update(['status' => AgentStatus::Active]);
        broadcast(new AgentUpdatedEvent($agent));

        if ($healthy) {
            Log::info("Hermes agent {$agent->harness_agent_id} activated on server {$agent->server_id}");
        } else {
            Log::warning("Hermes agent {$agent->harness_agent_id} deployed but health check failed — marking active anyway");
        }
    }

    public function updateAgent(Agent $agent, CommandExecutor $executor): void
    {
        $updateService = app(AgentUpdateScriptService::class);
        if ($agent->server?->isDocker()) {
            $script = $updateService->generateHermesScript($agent);
            $executor->execScript($script);
        } else {
            $scriptUrl = $updateService->buildSignedUrl($agent);
            $executor->execScript($scriptUrl);
        }

        // Update local state
        $agent->update([
            'is_syncing' => false,
            'last_synced_at' => now(),
        ]);
        broadcast(new AgentUpdatedEvent($agent));
    }

    public function removeAgent(Agent $agent, CommandExecutor $executor): void
    {
        $hermesHome = $this->agentDir($agent);
        $profileName = self::browserProfileName($agent);

        try {
            // Stop and uninstall Hermes gateway
            $executor->exec("export HERMES_HOME={$hermesHome} XDG_RUNTIME_DIR=/run/user/\$(id -u) && /root/.local/bin/hermes gateway stop 2>/dev/null || true");
            $executor->exec("export HERMES_HOME={$hermesHome} XDG_RUNTIME_DIR=/run/user/\$(id -u) && /root/.local/bin/hermes gateway uninstall 2>/dev/null || true");

            // Stop and remove browser display services
            $executor->exec("systemctl stop chrome-{$profileName} x11vnc-{$profileName} websockify-{$profileName} 2>/dev/null || true");
            $executor->exec("systemctl disable chrome-{$profileName} x11vnc-{$profileName} websockify-{$profileName} 2>/dev/null || true");
            $executor->exec("rm -f /etc/systemd/system/chrome-{$profileName}.service /etc/systemd/system/x11vnc-{$profileName}.service /etc/systemd/system/websockify-{$profileName}.service");
            $executor->exec("rm -f /etc/caddy/conf.d/{$profileName}.caddy && systemctl reload caddy 2>/dev/null || true");
            $executor->exec("rm -rf /root/.chrome-profiles/{$profileName}");

            // Remove email check crontab entry
            $marker = "# provision-email-check-{$agent->harness_agent_id}";
            $executor->exec("(crontab -l 2>/dev/null | grep -v '{$marker}') | crontab - 2>/dev/null || true");

            // Remove agent directory
            $executor->exec("rm -rf {$hermesHome}");

            Log::info("Hermes agent {$agent->harness_agent_id} removed from server {$agent->server_id}");
        } catch (\RuntimeException $e) {
            Log::warning("Failed to fully remove Hermes agent {$agent->harness_agent_id}: {$e->getMessage()}");
        }
    }

    public function restartGateway(Server $server, CommandExecutor $executor): void
    {
        $agents = $server->agents()->where('harness_type', 'hermes')->get();

        foreach ($agents as $agent) {
            $this->restartAgentService($agent, $executor);
        }
    }

    public function checkHealth(Agent $agent, CommandExecutor $executor): bool
    {
        $hermesHome = $this->agentDir($agent);

        try {
            $output = $executor->exec("export HERMES_HOME={$hermesHome} XDG_RUNTIME_DIR=/run/user/\$(id -u) && /root/.local/bin/hermes gateway status 2>&1");

            return str_contains($output, 'running') || str_contains($output, 'active');
        } catch (\RuntimeException) {
            return false;
        }
    }

    public function agentDir(Agent $agent): string
    {
        return "/root/.hermes-{$agent->harness_agent_id}";
    }

    public function formatModelConfig(Agent $agent): string|array
    {
        $agent->loadMissing(['server.team.apiKeys', 'server.team.managedApiKey']);

        $hasDirectKey = $agent->server?->team?->apiKeys
            ?->where('is_active', true)
            ->isNotEmpty() ?? false;

        $hasManagedKey = $agent->server?->team?->managedApiKey !== null;

        // OpenRouter: use provider/model format (Hermes auto-detects from env var)
        if ($hasManagedKey && ! $hasDirectKey) {
            $provider = LlmProvider::forModel($agent->model_primary);
            $prefix = match ($provider) {
                LlmProvider::Anthropic => 'anthropic',
                LlmProvider::OpenAi => 'openai',
                default => '',
            };

            return $prefix
                ? "{$prefix}/{$agent->model_primary}"
                : $agent->model_primary;
        }

        $provider = LlmProvider::forModel($agent->model_primary);

        if (! $provider) {
            return $agent->model_primary;
        }

        return match ($provider) {
            LlmProvider::Anthropic => "anthropic/{$agent->model_primary}",
            LlmProvider::OpenAi => "openai/{$agent->model_primary}",
            LlmProvider::OpenRouter => $agent->model_primary,
        };
    }

    /**
     * Build a fallback model string for auto-failover.
     * Uses a different model from the same provider via OpenRouter.
     */
    private function buildFallbackModel(Agent $agent, string $primaryModel): ?string
    {
        // If primary is Claude Sonnet, fall back to Claude Haiku (cheaper, faster)
        if (str_contains($primaryModel, 'claude-sonnet')) {
            return str_replace('claude-sonnet', 'claude-haiku', $primaryModel);
        }

        // If primary is Claude Opus, fall back to Claude Sonnet
        if (str_contains($primaryModel, 'claude-opus')) {
            return str_replace('claude-opus', 'claude-sonnet', $primaryModel);
        }

        // If primary is GPT-4, fall back to GPT-4o-mini
        if (str_contains($primaryModel, 'gpt-4o') && ! str_contains($primaryModel, 'mini')) {
            return str_replace('gpt-4o', 'gpt-4o-mini', $primaryModel);
        }

        // Default: no fallback
        return null;
    }

    // --- Browser profile name (shared with OpenClaw pattern) ---

    public static function browserProfileName(Agent $agent): string
    {
        return 'hermes-'.strtolower(preg_replace('/[^a-zA-Z0-9]/', '-', $agent->name));
    }

    // --- Private helpers ---

    private function writeWorkspaceFiles(Agent $agent, CommandExecutor $executor): void
    {
        $hermesHome = $this->agentDir($agent);

        // SOUL.md — agent identity/persona
        $executor->writeFile("{$hermesHome}/SOUL.md", $this->buildSoulContent($agent));

        // USER.md — user profile (loaded into system prompt at session start)
        $executor->exec("mkdir -p {$hermesHome}/memories");
        $executor->writeFile("{$hermesHome}/memories/USER.md", $this->buildUserMd($agent));

        // MEMORY.md — agent self-knowledge (own email, workspace, credentials)
        $executor->writeFile("{$hermesHome}/memories/MEMORY.md", $this->buildMemoryMd($agent));

        // AGENTS.md — system prompt + tools documentation merged
        // Hermes auto-discovers AGENTS.md and injects it into the system prompt.
        // TOOLS.md is NOT auto-loaded by Hermes, so we merge everything into AGENTS.md.
        $agentsMd = $agent->system_prompt ?? '';

        $toolsMd = $agent->tools_config ?? '';

        if ($agent->emailConnection?->mailboxkit_inbox_id) {
            $toolsMd .= "\n\n".AgentInstallScriptService::emailToolsMd($agent->emailConnection);
        }

        $toolsMd .= "\n\n".self::buildBrowserToolsMd($agent);
        $toolsMd .= "\n\n".self::buildGitToolsMd($agent);
        $toolsMd .= "\n\n".self::buildWorkspaceToolsMd($hermesHome);

        if (trim($toolsMd)) {
            $agentsMd .= "\n\n# Tools & Capabilities\n\n".trim($toolsMd);
        }

        if (trim($agentsMd)) {
            $executor->writeFile("{$hermesHome}/AGENTS.md", trim($agentsMd));
        }
    }

    private function buildSoulContent(Agent $agent): string
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

    private function buildUserMd(Agent $agent): string
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

    private function buildMemoryMd(Agent $agent): string
    {
        $lines = [];

        $lines[] = "My name is {$agent->name}.";

        $agentEmail = $agent->emailConnection?->email_address;
        if ($agentEmail) {
            $lines[] = "My email address is {$agentEmail}.";
            $lines[] = 'I ALWAYS use the MailboxKit API (curl + exec) for ALL email — sending, receiving, replying. Never use himalaya, sendmail, or any other email tool. MailboxKit is my only email system. See the mailboxkit skill for curl commands.';
        }

        $lines[] = "My workspace is at {$this->agentDir($agent)}/workspace/.";
        $lines[] = 'I have my own isolated browser (real Chrome, not headless) connected via CDP.';
        $lines[] = 'I have isolated Git credentials — my commits use my name and email.';

        return implode("\n§\n", $lines);
    }

    private function buildEnvFile(Agent $agent): string
    {
        $lines = [];

        // Allow all users by default (no pairing required)
        $lines[] = 'GATEWAY_ALLOW_ALL_USERS=true';

        // Git/GitHub credential isolation
        $hermesHome = $this->agentDir($agent);
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

        // Add managed API key if no user-provided OpenRouter key exists
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

        // Team environment variables (custom API keys, secrets, config)
        $agent->loadMissing('server.team.envVars');
        foreach ($agent->server?->team?->envVars ?? [] as $envVar) {
            $lines[] = "{$envVar->key}={$envVar->value}";
        }

        // Hermes API server — each agent gets its own on a unique port
        $apiServerKey = $agent->api_server_key ?: bin2hex(random_bytes(24));
        $apiServerPort = $agent->api_server_port ?? $this->assignApiServerPort($agent);
        $agent->updateQuietly(['api_server_key' => $apiServerKey]);
        $lines[] = 'API_SERVER_ENABLED=true';
        $lines[] = 'API_SERVER_HOST=0.0.0.0';
        $lines[] = "API_SERVER_PORT={$apiServerPort}";
        $lines[] = "API_SERVER_KEY={$apiServerKey}";

        return implode("\n", $lines);
    }

    private function assignApiServerPort(Agent $agent): int
    {
        $basePort = 8642;
        $existingCount = $agent->server
            ? $agent->server->agents()->whereNotNull('api_server_port')->count()
            : 0;

        $port = $basePort + $existingCount;
        $agent->update(['api_server_port' => $port]);

        return $port;
    }

    private function buildConfigYaml(Agent $agent): string
    {
        $model = $this->formatModelConfig($agent);
        $modelStr = is_array($model) ? $model['primary'] : $model;

        // Determine fallback model (use a cheaper/different provider)
        $fallbackModel = $this->buildFallbackModel($agent, $modelStr);

        $lines = [
            '# Hermes Agent config — managed by Provision',
            "model: \"{$modelStr}\"",
            '',
        ];

        // Fallback provider — auto-failover on 429/5xx errors
        if ($fallbackModel) {
            $lines[] = 'fallback_model:';
            $lines[] = "  model: \"{$fallbackModel}\"";
            $lines[] = '';
        }

        $timezone = $agent->server?->team?->timezone ?? 'UTC';

        $lines = array_merge($lines, [
            "timezone: \"{$timezone}\"",
            '',
            'terminal:',
            '  backend: local',
            "  cwd: \"{$this->agentDir($agent)}/workspace\"",
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
            '# Subagent delegation — parallel task execution with cheaper model',
            'delegation:',
            '  model: "google/gemini-2.5-flash"',
            '  max_iterations: 50',
            '',
            '# Event hooks — report activity back to Provision',
            'hooks:',
            '  enabled: true',
            '',
        ]);

        return implode("\n", $lines);
    }

    private function configureChannels(Agent $agent, CommandExecutor $executor): void
    {
        $hermesHome = $this->agentDir($agent);

        $envLines = [];

        // Telegram
        if ($agent->telegramConnection?->bot_token) {
            $envLines[] = "TELEGRAM_BOT_TOKEN={$agent->telegramConnection->bot_token}";
        }

        // Slack
        if ($agent->slackConnection?->bot_token && $agent->slackConnection?->app_token) {
            $envLines[] = "SLACK_BOT_TOKEN={$agent->slackConnection->bot_token}";
            $envLines[] = "SLACK_APP_TOKEN={$agent->slackConnection->app_token}";
        }

        // Discord
        if ($agent->discordConnection?->token) {
            $envLines[] = "DISCORD_BOT_TOKEN={$agent->discordConnection->token}";
            if ($agent->discordConnection->guild_id) {
                $envLines[] = "DISCORD_GUILD_ID={$agent->discordConnection->guild_id}";
            }
        }

        // Append channel env vars to .env
        if (! empty($envLines)) {
            $existingEnv = '';

            try {
                $existingEnv = $executor->readFile("{$hermesHome}/.env");
            } catch (\RuntimeException) {
            }

            // Remove old channel lines and append new ones
            $existingLines = array_filter(explode("\n", $existingEnv), function ($line) {
                return ! preg_match('/^(TELEGRAM_|SLACK_|DISCORD_)/', $line);
            });

            $executor->writeFile("{$hermesHome}/.env", implode("\n", array_merge(array_filter($existingLines), $envLines)));
        }
    }

    // --- Browser display setup (Xvfb + Chrome + VNC, same as OpenClaw) ---

    private function setupBrowserDisplay(Agent $agent, CommandExecutor $executor): void
    {
        $profileName = self::browserProfileName($agent);
        $hermesHome = $this->agentDir($agent);
        $chromeExec = config('openclaw.browser_executable_path', '/usr/bin/google-chrome-stable');
        $proxyPacFlag = app()->bound(AgentProxyProvider::class) ? ' --proxy-pac-url=http://127.0.0.1:8321/proxy.pac' : '';

        $script = <<<BASH
        # Find next available display number
        DISPLAY_NUM=1
        while [ -f /etc/systemd/system/xvfb-display-\${DISPLAY_NUM}.service ]; do
          DISPLAY_NUM=\$((DISPLAY_NUM + 1))
        done
        VNC_PORT=\$((5900 + DISPLAY_NUM))
        WS_PORT=\$((6080 + DISPLAY_NUM))
        CDP_PORT=\$((9222 + DISPLAY_NUM))

        mkdir -p /root/.chrome-profiles/{$profileName}

        # Xvfb — virtual framebuffer for this agent
        cat > /etc/systemd/system/xvfb-display-\${DISPLAY_NUM}.service << UNIT_EOF
        [Unit]
        Description=Xvfb display :\${DISPLAY_NUM} for {$profileName}
        After=network.target

        [Service]
        ExecStart=/usr/bin/Xvfb :\${DISPLAY_NUM} -screen 0 1440x900x24 -ac +extension GLX +render -noreset
        Restart=always
        RestartSec=3

        [Install]
        WantedBy=multi-user.target
        UNIT_EOF

        # Chrome — runs on the agent's display with its own user data dir
        cat > /etc/systemd/system/chrome-{$profileName}.service << UNIT_EOF
        [Unit]
        Description=Google Chrome for {$profileName}
        After=xvfb-display-\${DISPLAY_NUM}.service
        Requires=xvfb-display-\${DISPLAY_NUM}.service

        [Service]
        Environment=DISPLAY=:\${DISPLAY_NUM}
        ExecStart={$chromeExec} --no-sandbox --disable-gpu --no-first-run --remote-debugging-port=\${CDP_PORT} --user-data-dir=/root/.chrome-profiles/{$profileName} --ozone-override-screen-size=1440,900 --window-size=1440,900{$proxyPacFlag}
        Restart=always
        RestartSec=5

        [Install]
        WantedBy=multi-user.target
        UNIT_EOF

        # x11vnc — captures this agent's display
        cat > /etc/systemd/system/x11vnc-{$profileName}.service << UNIT_EOF
        [Unit]
        Description=x11vnc for {$profileName}
        After=xvfb-display-\${DISPLAY_NUM}.service
        Requires=xvfb-display-\${DISPLAY_NUM}.service

        [Service]
        Environment=DISPLAY=:\${DISPLAY_NUM}
        ExecStart=/usr/bin/x11vnc -display :\${DISPLAY_NUM} -forever -shared -rfbauth /root/.vnc/passwd -rfbport \${VNC_PORT} -noxdamage -noxfixes
        Restart=always
        RestartSec=3

        [Install]
        WantedBy=multi-user.target
        UNIT_EOF

        # websockify — WebSocket proxy for noVNC
        cat > /etc/systemd/system/websockify-{$profileName}.service << UNIT_EOF
        [Unit]
        Description=noVNC websockify for {$profileName}
        After=x11vnc-{$profileName}.service
        Requires=x11vnc-{$profileName}.service

        [Service]
        ExecStart=/usr/bin/websockify --web=/usr/share/novnc \${WS_PORT} localhost:\${VNC_PORT}
        Restart=always
        RestartSec=3

        [Install]
        WantedBy=multi-user.target
        UNIT_EOF

        systemctl daemon-reload
        systemctl enable --now xvfb-display-\${DISPLAY_NUM} chrome-{$profileName} x11vnc-{$profileName} websockify-{$profileName}

        # Caddy route — path-based routing for this agent's noVNC
        mkdir -p /etc/caddy/conf.d
        cat > /etc/caddy/conf.d/{$profileName}.caddy << CADDY_EOF
        handle_path /browser/{$profileName}/* {
            reverse_proxy localhost:\${WS_PORT}
        }
        CADDY_EOF
        systemctl reload caddy 2>/dev/null || true

        # Write CDP URL to agent's .env so Hermes uses the real Chrome
        echo "BROWSER_CDP_URL=ws://127.0.0.1:\${CDP_PORT}" >> {$hermesHome}/.env
        BASH;

        try {
            $executor->exec($script);
            Log::info("Browser display set up for Hermes agent {$agent->harness_agent_id} ({$profileName})");
        } catch (\RuntimeException $e) {
            Log::warning("Browser display setup failed for Hermes agent {$agent->harness_agent_id}: {$e->getMessage()}");
        }
    }

    // --- Git credentials ---

    private function setupGitConfig(Agent $agent, CommandExecutor $executor): void
    {
        $hermesHome = $this->agentDir($agent);
        $executor->exec("mkdir -p {$hermesHome}/.gh");

        // Only write .gitconfig if empty or missing
        $existingGitconfig = '';
        try {
            $existingGitconfig = $executor->readFile("{$hermesHome}/.gitconfig");
        } catch (\RuntimeException) {
        }

        if (empty(trim($existingGitconfig))) {
            $email = $agent->emailConnection?->email_address
                ?? "{$agent->harness_agent_id}@noreply.openclaw.ai";
            $executor->writeFile("{$hermesHome}/.gitconfig",
                "[user]\n    name = {$agent->name}\n    email = {$email}\n");
        }
    }

    // --- Email check script (lightweight, no LLM cost) ---

    private function deployEmailCheckScript(Agent $agent, CommandExecutor $executor): void
    {
        $agentId = $agent->harness_agent_id;
        $hermesHome = $this->agentDir($agent);

        $script = AgentScheduleService::buildEmailCheckScript($agentId, $hermesHome);
        $script = str_replace(['__AGENT_DIR__', '__AGENT_ID__'], [$hermesHome, $agentId], $script);
        $executor->writeFile("{$hermesHome}/check-email.sh", $script);
        $executor->exec("chmod +x {$hermesHome}/check-email.sh");

        // Install crontab entry (idempotent)
        $marker = "# provision-email-check-{$agentId}";
        $cronLine = "*/5 * * * * {$hermesHome}/check-email.sh >> {$hermesHome}/email-check.log 2>&1 {$marker}";
        $executor->exec("(crontab -l 2>/dev/null | grep -v '{$marker}'; echo '{$cronLine}') | crontab -");
    }

    // --- MailboxKit skill deployment ---

    private function deployMailboxKitSkill(Agent $agent, CommandExecutor $executor): void
    {
        $hermesHome = $this->agentDir($agent);
        $emailConnection = $agent->emailConnection;

        $skillContent = file_get_contents(resource_path('skills/mailboxkit/SKILL.md'));
        $skillContent = str_replace('$MAILBOXKIT_API_KEY', config('mailboxkit.api_key'), $skillContent);
        $skillContent = str_replace('$MAILBOXKIT_INBOX_ID', (string) $emailConnection->mailboxkit_inbox_id, $skillContent);
        $skillContent = str_replace('$MAILBOXKIT_EMAIL', $emailConnection->email_address, $skillContent);

        $skillDir = "{$hermesHome}/skills/mailboxkit";
        $executor->exec("mkdir -p {$skillDir}");
        $executor->writeFile("{$skillDir}/SKILL.md", $skillContent);
    }

    // --- TOOLS.md sections ---

    private static function buildBrowserToolsMd(Agent $agent): string
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

    private static function buildGitToolsMd(Agent $agent): string
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

    private static function buildWorkspaceToolsMd(string $hermesHome): string
    {
        return implode("\n", [
            '## Workspace',
            '',
            "Your workspace is at `{$hermesHome}/workspace/`.",
            'Save all work output, files, and artifacts here.',
            'This directory persists across sessions.',
        ]);
    }

    // --- Service management ---

    private function restartAgentService(Agent $agent, CommandExecutor $executor): void
    {
        $hermesHome = $this->agentDir($agent);

        try {
            if ($agent->server?->isDocker()) {
                $executor->exec("pkill -f 'hermes.*gateway.*{$hermesHome}' 2>/dev/null; exit 0");
                sleep(2);
                $executor->exec("export HERMES_HOME={$hermesHome} DISPLAY=:99 && nohup /root/.local/bin/hermes gateway run >> {$hermesHome}/gateway.log 2>&1 &");
            } else {
                $executor->execWithRetry("export HERMES_HOME={$hermesHome} XDG_RUNTIME_DIR=/run/user/\$(id -u) && /root/.local/bin/hermes gateway restart 2>&1");
            }
            sleep(3);
        } catch (\RuntimeException $e) {
            Log::warning("Failed to restart Hermes agent {$agent->harness_agent_id}: {$e->getMessage()}");
        }
    }
}
