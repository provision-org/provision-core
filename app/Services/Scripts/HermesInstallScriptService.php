<?php

namespace App\Services\Scripts;

use App\Contracts\Modules\AgentProxyProvider;
use App\Models\Agent;
use App\Models\Team;
use App\Services\AgentInstallScriptService;
use App\Services\AgentScheduleService;
use App\Services\Harness\HermesDriver;

class HermesInstallScriptService
{
    public function __construct(
        private HermesDriver $hermesDriver,
    ) {}

    /**
     * Create an HMAC-signed URL for the Hermes install script (10-min expiry).
     */
    public function buildSignedUrl(Agent $agent): string
    {
        $expiresAt = now()->addMinutes(10)->timestamp;
        $signature = hash_hmac('sha256', "hermes-install|{$agent->id}|{$expiresAt}", config('app.key'));

        return url("/api/agents/{$agent->id}/hermes-install-script?expires_at={$expiresAt}&signature={$signature}");
    }

    /**
     * Create an HMAC-signed callback URL for the agent ready webhook.
     */
    public function buildCallbackUrl(Agent $agent): string
    {
        $expiresAt = now()->addMinutes(30)->timestamp;
        $signature = hash_hmac('sha256', "callback|{$agent->id}|{$expiresAt}", config('app.key'));

        return url("/api/webhooks/agent-ready?agent_id={$agent->id}&expires_at={$expiresAt}&signature={$signature}");
    }

    /**
     * Generate the full bash install script for a Hermes agent.
     *
     * This replaces the 20+ SSH operations in HermesDriver::createAgent() with a single
     * self-contained script that can be fetched and executed on the server.
     */
    public function generateScript(Agent $agent): string
    {
        $eagerLoads = [
            'server.team.apiKeys',
            'server.team.envVars',
            'slackConnection',
            'telegramConnection',
            'discordConnection',
            'emailConnection',
            'tools',
            'team.owner',
        ];

        $eagerLoads[] = 'server.team.managedApiKey';

        $agent->loadMissing($eagerLoads);

        $agentId = $agent->harness_agent_id;
        $hermesHome = $this->hermesDriver->agentDir($agent);
        $callbackUrl = $this->buildCallbackUrl($agent);
        $profileName = HermesDriver::browserProfileName($agent);
        $isDocker = $agent->server?->isDocker();

        $lines = [
            '#!/bin/bash',
            'set -e',
            '',
            '# --- Hermes Agent Install Script ---',
            "# Agent: {$agent->name} ({$agentId})",
            "# Home: {$hermesHome}",
            '# Generated: '.now()->toIso8601String(),
            '',
        ];

        // Progress callback + error trap (skip callbacks in Docker — no network access to app)
        $lines[] = '# --- Progress & Error Callbacks ---';
        if ($isDocker) {
            $lines[] = 'ping_progress() { true; }';
            $lines[] = 'report_error() { echo "Hermes install failed at line $1" >&2; exit 1; }';
        } else {
            $lines[] = 'ping_progress() {';
            $lines[] = "  curl -sS -X POST '{$callbackUrl}' -d \"status=progress&step=\$1\" || true";
            $lines[] = '}';
            $lines[] = '';
            $lines[] = 'report_error() {';
            $lines[] = "  curl -sS -X POST '{$callbackUrl}' -d 'status=error&error_message='\"Hermes install failed at line \$1\" || true";
            $lines[] = '  exit 1';
            $lines[] = '}';
        }
        $lines[] = '';
        $lines[] = 'trap \'report_error $LINENO\' ERR';
        $lines[] = '';

        // 1. Create directory structure
        $lines[] = '# --- Step 1: Create Directories ---';
        $lines[] = 'ping_progress "creating_directories"';
        $lines[] = "mkdir -p {$hermesHome}/workspace {$hermesHome}/.gh {$hermesHome}/skills {$hermesHome}/memories";
        $lines[] = '';

        // 2. Initialize Hermes
        $lines[] = '# --- Step 2: Initialize Hermes ---';
        $lines[] = 'ping_progress "initializing_hermes"';
        $lines[] = "HERMES_HOME={$hermesHome} /root/.local/bin/hermes setup --non-interactive 2>&1 || true";
        $lines[] = '';

        // 3. Write SOUL.md
        $lines[] = '# --- Step 3: Write Workspace Files ---';
        $lines[] = 'ping_progress "writing_workspace_files"';
        $soulContent = $this->buildSoulContent($agent);
        $lines[] = $this->buildHeredoc("{$hermesHome}/SOUL.md", $soulContent);
        $lines[] = '';

        // 4. Write USER.md
        $userMd = $this->buildUserMd($agent);
        $lines[] = $this->buildHeredoc("{$hermesHome}/memories/USER.md", $userMd);
        $lines[] = '';

        // 5. Write MEMORY.md
        $memoryMd = $this->buildMemoryMd($agent);
        $lines[] = $this->buildHeredoc("{$hermesHome}/memories/MEMORY.md", $memoryMd);
        $lines[] = '';

        // 6. Write AGENTS.md (system_prompt + tools documentation merged)
        $agentsMd = $this->buildAgentsMd($agent);
        if (trim($agentsMd)) {
            $lines[] = $this->buildHeredoc("{$hermesHome}/AGENTS.md", trim($agentsMd));
            $lines[] = '';
        }

        // 7. Write config.yaml
        $lines[] = '# --- Step 4: Write Config Files ---';
        $lines[] = 'ping_progress "writing_config"';
        $configYaml = $this->buildConfigYaml($agent);
        $lines[] = $this->buildHeredoc("{$hermesHome}/config.yaml", $configYaml);
        $lines[] = '';

        // 8. Write .env (base + channels merged into one file)
        $envContent = $this->buildFullEnvFile($agent);
        $lines[] = $this->buildHeredoc("{$hermesHome}/.env", $envContent);
        $lines[] = '';

        // 9. Write gateway.json
        $gatewayJson = json_encode([
            'reset_by_platform' => [
                'slack' => ['mode' => 'idle', 'idle_minutes' => 30],
                'telegram' => ['mode' => 'idle', 'idle_minutes' => 30],
                'discord' => ['mode' => 'idle', 'idle_minutes' => 30],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $lines[] = $this->buildHeredoc("{$hermesHome}/gateway.json", $gatewayJson);
        $lines[] = '';

        // 10. Browser display setup (Xvfb + Chrome + VNC + websockify + Caddy)
        $lines[] = '# --- Step 5: Browser Display Setup ---';
        $lines[] = 'ping_progress "setting_up_browser"';
        $lines[] = $this->buildBrowserDisplayScript($agent);
        $lines[] = '';

        // 11. Git config
        $lines[] = '# --- Step 6: Git Config ---';
        $lines[] = 'ping_progress "configuring_git"';
        $gitEmail = $agent->emailConnection?->email_address
            ?? "{$agentId}@noreply.openclaw.ai";
        $gitName = $agent->name;
        $gitConfig = "[user]\n    name = {$gitName}\n    email = {$gitEmail}";
        $lines[] = "if [ ! -s {$hermesHome}/.gitconfig ]; then";
        $lines[] = $this->buildHeredoc("{$hermesHome}/.gitconfig", $gitConfig);
        $lines[] = 'fi';
        $lines[] = '';

        // 12. Email check script + crontab + MailboxKit skill (if email connected)
        if ($agent->emailConnection?->mailboxkit_inbox_id) {
            $lines[] = '# --- Step 7: Email Integration ---';
            $lines[] = 'ping_progress "deploying_email"';
            $lines[] = $this->buildEmailCheckSection($agent);
            $lines[] = '';
            $lines[] = $this->buildMailboxKitSkillSection($agent);
            $lines[] = '';
        }

        // 13. Initialize ByteRover memory
        $lines[] = '# --- Step 8: ByteRover Memory ---';
        $lines[] = 'ping_progress "initializing_memory"';
        $lines[] = "if [ ! -d {$hermesHome}/.brv ] && command -v /root/.brv-cli/bin/brv &>/dev/null; then";
        $lines[] = "  cd {$hermesHome} && /root/.brv-cli/bin/brv init 2>/dev/null || true";
        $lines[] = 'fi';
        $lines[] = '';

        // 14. Install and start Hermes gateway
        $lines[] = '# --- Step 9: Install & Start Gateway ---';
        $lines[] = 'ping_progress "installing_gateway"';
        $lines[] = "export HERMES_HOME={$hermesHome}";
        if ($isDocker) {
            $lines[] = 'export DISPLAY=:99';
            $lines[] = "nohup /root/.local/bin/hermes gateway run >> {$hermesHome}/gateway.log 2>&1 &";
        } else {
            $lines[] = 'export XDG_RUNTIME_DIR=/run/user/$(id -u)';
            $lines[] = '/root/.local/bin/hermes gateway install 2>&1 || true';
            $lines[] = '/root/.local/bin/hermes gateway start 2>&1 || true';
        }
        $lines[] = '';

        // 15. Health check + callback
        $lines[] = '# --- Step 10: Health Check & Callback ---';
        $lines[] = 'ping_progress "health_check"';
        $lines[] = 'sleep 5';
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
        if ($isDocker) {
            $lines[] = 'echo "Hermes agent setup complete (healthy=$HEALTHY)"';
        } else {
            $lines[] = 'if [ "$HEALTHY" -eq 1 ]; then';
            $lines[] = "  curl -sS -X POST '{$callbackUrl}' -d 'status=ready' || true";
            $lines[] = 'else';
            $lines[] = "  curl -sS -X POST '{$callbackUrl}' -d 'status=ready&warning=health_check_failed' || true";
            $lines[] = 'fi';
        }

        return implode("\n", $lines)."\n";
    }

    // --- Content builders (mirroring HermesDriver private methods) ---

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
        $hermesHome = $this->hermesDriver->agentDir($agent);

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

    private function buildAgentsMd(Agent $agent): string
    {
        $hermesHome = $this->hermesDriver->agentDir($agent);
        $agentsMd = $agent->system_prompt ?? '';

        $toolsMd = $agent->tools_config ?? '';

        if ($agent->emailConnection?->mailboxkit_inbox_id) {
            $toolsMd .= "\n\n".AgentInstallScriptService::emailToolsMd($agent->emailConnection);
        }

        $toolsMd .= "\n\n".self::buildBrowserToolsMd();
        $toolsMd .= "\n\n".self::buildGitToolsMd();
        $toolsMd .= "\n\n".self::buildWorkspaceToolsMd($hermesHome);

        if (trim($toolsMd)) {
            $agentsMd .= "\n\n# Tools & Capabilities\n\n".trim($toolsMd);
        }

        return $agentsMd;
    }

    private function buildConfigYaml(Agent $agent): string
    {
        $model = $this->hermesDriver->formatModelConfig($agent);
        $modelStr = is_array($model) ? $model['primary'] : $model;
        $hermesHome = $this->hermesDriver->agentDir($agent);

        return implode("\n", [
            '# Hermes Agent config — managed by Provision',
            "model: \"{$modelStr}\"",
            '',
            'terminal:',
            '  backend: local',
            "  cwd: \"{$hermesHome}/workspace\"",
            '  timeout: 180',
            '',
            'memory:',
            '  enabled: true',
            '',
            'skills:',
            '  agent_managed: true',
            '  deny_list: himalaya',
            '',
            'display:',
            '  tool_progress: new',
            '',
        ]);
    }

    /**
     * Build the complete .env content with base vars + channel tokens merged.
     */
    private function buildFullEnvFile(Agent $agent): string
    {
        $hermesHome = $this->hermesDriver->agentDir($agent);

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

        // MailboxKit credentials if email connected
        if ($agent->emailConnection?->mailboxkit_inbox_id) {
            $lines[] = 'MAILBOXKIT_API_KEY='.config('mailboxkit.api_key');
            $lines[] = "MAILBOXKIT_INBOX_ID={$agent->emailConnection->mailboxkit_inbox_id}";
            $lines[] = "MAILBOXKIT_EMAIL={$agent->emailConnection->email_address}";
        }

        // Channel tokens (Telegram, Slack, Discord)
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

        // Hermes API server — enables web chat via OpenAI-compatible HTTP API
        $apiServerKey = bin2hex(random_bytes(24));
        $lines[] = 'API_SERVER_ENABLED=true';
        $lines[] = 'API_SERVER_HOST=0.0.0.0';
        $lines[] = 'API_SERVER_PORT=8642';
        $lines[] = "API_SERVER_KEY={$apiServerKey}";

        return implode("\n", $lines);
    }

    /**
     * Build the browser display setup bash block (Xvfb + Chrome + VNC + websockify + Caddy).
     */
    private function buildBrowserDisplayScript(Agent $agent): string
    {
        $profileName = HermesDriver::browserProfileName($agent);
        $hermesHome = $this->hermesDriver->agentDir($agent);
        $chromeExec = config('openclaw.browser_executable_path', '/usr/bin/google-chrome-stable');
        $proxyPacFlag = app()->bound(AgentProxyProvider::class) ? ' --proxy-pac-url=http://127.0.0.1:8321/proxy.pac' : '';
        $isDocker = $agent->server?->isDocker();

        if ($isDocker) {
            // Docker: use shared display :99, just start Chrome with its own profile
            return <<<BASH
CDP_PORT=\$((9222 + \$(ls /root/.chrome-profiles/ 2>/dev/null | wc -l)))

mkdir -p /root/.chrome-profiles/{$profileName}

export DISPLAY=:99
nohup {$chromeExec} --no-sandbox --disable-gpu --no-first-run \
  --remote-debugging-port=\${CDP_PORT} \
  --user-data-dir=/root/.chrome-profiles/{$profileName} \
  --ozone-override-screen-size=1440,900 --window-size=1440,900{$proxyPacFlag} \
  > /dev/null 2>&1 &

sleep 2

echo "BROWSER_CDP_URL=ws://127.0.0.1:\${CDP_PORT}" >> {$hermesHome}/.env
BASH;
        }

        // Cloud: per-agent display + Chrome + VNC via systemd
        return <<<BASH
# Find next available display number
DISPLAY_NUM=1
while [ -f /etc/systemd/system/xvfb-display-\${DISPLAY_NUM}.service ]; do
  DISPLAY_NUM=\$((DISPLAY_NUM + 1))
done
VNC_PORT=\$((5900 + DISPLAY_NUM))
WS_PORT=\$((6080 + DISPLAY_NUM))
CDP_PORT=\$((9222 + DISPLAY_NUM))

mkdir -p /root/.chrome-profiles/{$profileName}

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
mkdir -p /etc/caddy/conf.d
cat > /etc/caddy/conf.d/{$profileName}.caddy << CADDY_EOF
handle_path /browser/{$profileName}/* {
    reverse_proxy localhost:\${WS_PORT}
}
CADDY_EOF
systemctl reload caddy 2>/dev/null || true

echo "BROWSER_CDP_URL=ws://127.0.0.1:\${CDP_PORT}" >> {$hermesHome}/.env
BASH;
    }

    /**
     * Build the email check script deployment + crontab section.
     */
    private function buildEmailCheckSection(Agent $agent): string
    {
        $agentId = $agent->harness_agent_id;
        $hermesHome = $this->hermesDriver->agentDir($agent);

        $script = AgentScheduleService::buildEmailCheckScript($agentId, $hermesHome);
        $script = str_replace(['__AGENT_DIR__', '__AGENT_ID__'], [$hermesHome, $agentId], $script);

        $marker = "# provision-email-check-{$agentId}";
        $cronLine = "*/5 * * * * {$hermesHome}/check-email.sh >> {$hermesHome}/email-check.log 2>&1 {$marker}";

        $lines = [];
        $lines[] = $this->buildHeredoc("{$hermesHome}/check-email.sh", $script);
        $lines[] = "chmod +x {$hermesHome}/check-email.sh";
        $lines[] = "(crontab -l 2>/dev/null | grep -v '{$marker}'; echo '{$cronLine}') | crontab -";

        return implode("\n", $lines);
    }

    /**
     * Build the MailboxKit skill deployment section.
     */
    private function buildMailboxKitSkillSection(Agent $agent): string
    {
        $hermesHome = $this->hermesDriver->agentDir($agent);
        $emailConnection = $agent->emailConnection;

        $skillContent = file_get_contents(resource_path('skills/mailboxkit/SKILL.md'));
        $skillContent = str_replace('$MAILBOXKIT_API_KEY', config('mailboxkit.api_key'), $skillContent);
        $skillContent = str_replace('$MAILBOXKIT_INBOX_ID', (string) $emailConnection->mailboxkit_inbox_id, $skillContent);
        $skillContent = str_replace('$MAILBOXKIT_EMAIL', $emailConnection->email_address, $skillContent);

        $skillDir = "{$hermesHome}/skills/mailboxkit";

        $lines = [];
        $lines[] = "mkdir -p {$skillDir}";
        $lines[] = $this->buildHeredoc("{$skillDir}/SKILL.md", $skillContent);

        return implode("\n", $lines);
    }

    // --- TOOLS.md section builders ---

    private static function buildBrowserToolsMd(): string
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

    private static function buildGitToolsMd(): string
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

    /**
     * Build a heredoc block that writes content to a file path.
     * Uses single-quoted delimiter to prevent shell variable expansion.
     */
    private function buildHeredoc(string $filePath, string $content): string
    {
        return "cat > {$filePath} << 'HEREDOC_EOF'\n{$content}\nHEREDOC_EOF";
    }
}
