<?php

namespace App\Services;

use App\Contracts\Modules\AgentEmailProvider;
use App\Contracts\Modules\AgentProxyProvider;
use App\Enums\LlmProvider;
use App\Models\Agent;
use App\Models\AgentApiToken;

class AgentInstallScriptService
{
    public function __construct(
        private ChannelConfigBuilder $configBuilder,
        private ModuleRegistry $moduleRegistry,
    ) {}

    /**
     * Create an HMAC-signed URL for the agent install script (10-min expiry).
     */
    public function buildSignedUrl(Agent $agent): string
    {
        $expiresAt = now()->addMinutes(10)->timestamp;
        $signature = hash_hmac('sha256', "install|{$agent->id}|{$expiresAt}", config('app.key'));

        return url("/api/agents/{$agent->id}/install-script?expires_at={$expiresAt}&signature={$signature}");
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
     * Generate the full bash install script for an agent.
     */
    public function generateScript(Agent $agent): string
    {
        $agent->loadMissing(['server.team.apiKeys', 'server.team.envVars', 'slackConnection', 'emailConnection', 'telegramConnection', 'discordConnection', 'tools']);

        $agentId = $agent->harness_agent_id;
        $agentDir = "/root/.openclaw/agents/{$agentId}";
        $configFile = '/root/.openclaw/openclaw.json';

        $lines = [
            '#!/bin/bash',
            'set -e',
            '',
            '# --- Agent Install Script ---',
            "# Agent: {$agent->name} ({$agentId})",
            '',
        ];

        // 0. Wait for openclaw.json to be created by SetupOpenClawOnServerJob
        $lines[] = '# Wait for openclaw.json (created by openclaw onboard during server setup)';
        $lines[] = 'WAIT_COUNT=0';
        $lines[] = "while [ ! -f {$configFile} ] && [ \$WAIT_COUNT -lt 90 ]; do";
        $lines[] = '  sleep 2';
        $lines[] = '  WAIT_COUNT=$((WAIT_COUNT + 1))';
        $lines[] = 'done';
        $lines[] = "if [ ! -f {$configFile} ]; then";
        $lines[] = "  echo 'ERROR: {$configFile} not found after 3 minutes'";
        $lines[] = '  exit 1';
        $lines[] = 'fi';
        $lines[] = '';

        // 1. Add agent to openclaw.json
        $lines[] = '# Add agent to openclaw.json';
        $lines[] = $this->buildAgentPatchScript($agent, $configFile);
        $lines[] = '';

        // 1b. Patch env section in openclaw.json with LLM provider keys
        $envConfigScript = $this->buildEnvConfigPatchScript($agent, $configFile);
        if ($envConfigScript) {
            $lines[] = '# Set LLM provider API keys in openclaw.json env section';
            $lines[] = $envConfigScript;
            $lines[] = '';
        }

        // 2. Add Slack account + binding if configured
        $slack = $agent->slackConnection;
        if ($slack && $slack->bot_token && $slack->app_token) {
            $lines[] = '# Add Slack account, binding, and enable plugin';
            $lines[] = $this->buildSlackPatchScript($agent, $slack, $configFile);
            $lines[] = $this->buildEnableSlackPluginScript($configFile);
            $lines[] = '';
        }

        // 2b. Add Telegram account + binding if configured
        $telegram = $agent->telegramConnection;
        if ($telegram && $telegram->bot_token) {
            $lines[] = '# Add Telegram account, binding, and enable plugin';
            $lines[] = $this->buildTelegramPatchScript($agent, $telegram, $configFile);
            $lines[] = $this->buildEnableChannelPluginScript('telegram', $configFile);
            $lines[] = '';
        }

        // 2c. Add Discord account + binding if configured
        $discord = $agent->discordConnection;
        if ($discord && $discord->token) {
            $lines[] = '# Add Discord account, binding, and enable plugin';
            $lines[] = $this->buildDiscordPatchScript($agent, $discord, $configFile);
            $lines[] = $this->buildEnableChannelPluginScript('discord', $configFile);
            $lines[] = '';
        }

        // 3. Install MailboxKit email skill if agent has email connection and module is active
        $emailConnection = $agent->emailConnection;
        $hasEmailModule = app()->bound(AgentEmailProvider::class);
        if ($hasEmailModule && $emailConnection?->mailboxkit_inbox_id) {
            $lines[] = '# Install MailboxKit email skill';
            $lines[] = $this->buildMailboxKitSkillDeployScript($agent);
            $lines[] = $this->buildMailboxKitSkillPatchScript($configFile);
            $lines[] = '';
        }

        // 4. Write workspace files
        $lines[] = '# Create agent directories and migrate knowledge/ to workspace/';
        $lines[] = "mkdir -p {$agentDir}";
        $lines[] = "test -d {$agentDir}/knowledge && ! -d {$agentDir}/workspace && mv {$agentDir}/knowledge {$agentDir}/workspace || true";
        $lines[] = "mkdir -p {$agentDir}/workspace";

        // Initialize ByteRover memory for this agent (isolated per-agent .brv/ directory)
        $lines[] = "if [ ! -d {$agentDir}/.brv ] && command -v /root/.brv-cli/bin/brv &>/dev/null; then";
        $lines[] = "  cd {$agentDir} && /root/.brv-cli/bin/brv init 2>/dev/null || true";
        $lines[] = 'fi';

        // Create isolated Git/GitHub config directory
        $lines[] = "mkdir -p {$agentDir}/.gh";
        $gitEmail = $agent->emailConnection?->email_address ?? "{$agentId}@noreply.openclaw.ai";
        $gitName = addcslashes($agent->name, "'");
        $lines[] = "if [ ! -s {$agentDir}/.gitconfig ]; then";
        $lines[] = "cat > {$agentDir}/.gitconfig << 'GITCONFIG_EOF'";
        $lines[] = '[user]';
        $lines[] = "    name = {$agent->name}";
        $lines[] = "    email = {$gitEmail}";
        $lines[] = 'GITCONFIG_EOF';
        $lines[] = 'fi';

        if ($agent->soul) {
            $lines[] = $this->buildHeredoc("{$agentDir}/SOUL.md", $agent->soul);
        }

        $systemPrompt = self::buildSystemPromptWithDelegation($agent);
        if ($systemPrompt) {
            $lines[] = $this->buildHeredoc("{$agentDir}/AGENTS.md", $systemPrompt);
        }

        if ($agent->identity) {
            $identityContent = $agent->identity;

            // Append account credentials to IDENTITY.md (not stored in DB for security)
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
        }

        if ($agent->user_context) {
            $lines[] = $this->buildHeredoc("{$agentDir}/USER.md", $agent->user_context);
        }

        // Write BOOTSTRAP.md: first-run onboarding checklist
        $lines[] = $this->buildHeredoc("{$agentDir}/BOOTSTRAP.md", self::buildBootstrapContent($agent));

        // Write HEARTBEAT.md: periodic checks (task polling always, email if connected)
        $lines[] = $this->buildHeredoc("{$agentDir}/HEARTBEAT.md", self::buildHeartbeatContent($emailConnection));

        // Write TOOLS.md: merge tools_config with email + tasks info
        $toolsMd = $agent->tools_config ?? '';
        if ($hasEmailModule && $emailConnection?->mailboxkit_inbox_id) {
            $toolsMd .= "\n\n".self::emailToolsMd($emailConnection);
        }

        $toolsMd .= "\n\n".self::envToolsMd($agent);
        $toolsMd .= "\n\n".self::workspaceToolsMd();
        $toolsMd .= "\n\n".self::gitToolsMd();
        $toolsMd .= "\n\n".self::browserToolsMd($agent);

        // Write per-agent .env with agent-specific credentials (tasks API + mailboxkit)
        $plainToken = self::ensureAgentApiToken($agent);
        $lines[] = $this->buildHeredoc("{$agentDir}/.env", self::buildAgentEnv($agent, $plainToken));
        if (trim($toolsMd)) {
            $lines[] = $this->buildHeredoc("{$agentDir}/TOOLS.md", trim($toolsMd));
        }
        $lines[] = '';

        // Write auth-profiles.json for OpenClaw's auth resolver.
        // Includes both openrouter and openai-codex providers (OpenClaw's internal
        // systems use openai-codex as fallback even with openrouter models).
        $agent->loadMissing('server.team.apiKeys', 'server.team.managedApiKey');
        $team = $agent->server?->team;
        $managedKey = $team?->managedApiKey;
        $openRouterKey = $team?->apiKeys()->where('provider', LlmProvider::OpenRouter)->where('is_active', true)->first();
        $apiKey = $openRouterKey?->api_key ?? $managedKey?->api_key;
        if ($apiKey) {
            $authProfiles = json_encode([
                'profiles' => [
                    'openrouter:default' => ['provider' => 'openrouter', 'type' => 'api_key', 'key' => $apiKey],
                    'openai-codex:default' => ['provider' => 'openai-codex', 'type' => 'api_key', 'key' => $apiKey],
                ],
                'order' => [
                    'openrouter' => ['openrouter:default'],
                    'openai-codex' => ['openai-codex:default'],
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            $lines[] = '# Write auth-profiles.json (OpenRouter + openai-codex providers)';
            $lines[] = "mkdir -p {$agentDir}/agent";
            $lines[] = $this->buildHeredoc("{$agentDir}/agent/auth-profiles.json", $authProfiles);
            // Also write to 'main' agent path (OpenClaw bug #24016 workaround)
            $lines[] = 'mkdir -p /root/.openclaw/agents/main/agent';
            $lines[] = $this->buildHeredoc('/root/.openclaw/agents/main/agent/auth-profiles.json', $authProfiles);
            $lines[] = '';
        }

        // 4b. Deploy provision-tasks skill (core, always deployed)
        array_push($lines, ...self::buildProvisionTasksSkillLines($agentDir, $plainToken, $this->buildHeredoc(...)));
        $lines[] = '';

        // 5. Write .env file
        $lines[] = '# Write environment variables';
        $lines[] = $this->buildEnvScript($agent);
        $lines[] = '';

        // 5b. Module-contributed install script sections (proxy, etc.)
        foreach ($this->moduleRegistry->installScriptSections($agent) as $section) {
            $lines[] = $section;
            $lines[] = '';
        }

        // 6. Set up per-agent browser display, Chrome, VNC, and Caddy route
        $profileName = self::browserProfileName($agent);
        $lines[] = '# Set up per-agent browser with isolated display and VNC';
        $lines[] = $this->buildBrowserDisplayScript($agent, $configFile);
        $lines[] = '';

        // 6b. Ensure device-pair stays disabled
        $lines[] = '# Disable device pairing (auto-approve all channel senders)';
        $lines[] = 'openclaw config set plugins.entries.device-pair.enabled false 2>/dev/null || true';
        $lines[] = '';

        // 7. Restart gateway so it picks up the new agent config
        $lines[] = '# Restart gateway';
        $lines[] = 'if command -v systemctl > /dev/null 2>&1 && [ -d /run/systemd/system ]; then';
        $lines[] = '  export XDG_RUNTIME_DIR=/run/user/$(id -u)';
        $lines[] = '  systemctl --user restart openclaw-gateway';
        $lines[] = 'else';
        $lines[] = '  pkill -f "openclaw gateway" 2>/dev/null || true';
        $lines[] = '  sleep 2';
        $lines[] = '  export DISPLAY=:99 && nohup openclaw gateway >> /root/.openclaw/logs/gateway.log 2>&1 &';
        $lines[] = 'fi';
        $lines[] = 'sleep 5';
        $lines[] = '';

        // 9. Health check + callback to notify the app
        $callbackUrl = $this->buildCallbackUrl($agent);
        $lines[] = '# Health check and callback';
        $lines[] = 'if openclaw health 2>/dev/null || (sleep 5 && openclaw health 2>/dev/null); then';
        $lines[] = "  curl -sS -X POST '{$callbackUrl}' -d 'status=ready' || true";
        $lines[] = 'else';
        $lines[] = "  curl -sS -X POST '{$callbackUrl}' -d 'status=error' || true";
        $lines[] = 'fi';

        return implode("\n", $lines)."\n";
    }

    private function buildAgentPatchScript(Agent $agent, string $configFile): string
    {
        $agentId = $agent->harness_agent_id;
        $agentDir = "/root/.openclaw/agents/{$agentId}";

        $agentData = json_encode([
            'id' => $agentId,
            'name' => $agent->name,
            'workspace' => $agentDir,
            'agentDir' => "{$agentDir}/agent",
            'model' => $agent->openclawModelConfig(),
        ], JSON_UNESCAPED_SLASHES);

        // Base64-encode to prevent bash injection via agent names or model configs
        $encodedData = base64_encode($agentData);

        $automationModel = LlmProvider::AUTOMATION_MODEL;

        return <<<BASH
        node -e '
          const fs = require("fs");
          const f = "{$configFile}";
          const c = JSON.parse(fs.readFileSync(f));
          if (Array.isArray(c.tools)) c.tools = {};
          if (c.tools) delete c.tools.profile;
          c.agents = c.agents || {};
          const agentData = JSON.parse(Buffer.from("{$encodedData}", "base64").toString());
          c.agents.list = (c.agents.list || []).filter(a => a.id !== agentData.id);
          c.agents.list.push(agentData);
          // Heartbeat: cheap model, light context, 55-min interval (stays in cache window)
          c.agents.defaults = c.agents.defaults || {};
          c.agents.defaults.heartbeat = c.agents.defaults.heartbeat || {};
          c.agents.defaults.heartbeat.model = "{$automationModel}";
          c.agents.defaults.heartbeat.lightContext = true;
          c.agents.defaults.heartbeat.every = "55m";
          // Compaction: higher reserve floor + memory flush before compaction
          c.agents.defaults.compaction = c.agents.defaults.compaction || {};
          c.agents.defaults.compaction.mode = "default";
          c.agents.defaults.compaction.reserveTokensFloor = 40000;
          c.agents.defaults.compaction.memoryFlush = c.agents.defaults.compaction.memoryFlush || {};
          c.agents.defaults.compaction.memoryFlush.enabled = true;
          c.agents.defaults.compaction.memoryFlush.softThresholdTokens = 4000;
          // Sub-agents: route to cheap model to prevent cost multiplication
          c.agents.defaults.subagents = c.agents.defaults.subagents || {};
          c.agents.defaults.subagents.model = "{$automationModel}";
          c.agents.defaults.subagents.maxConcurrent = 8;
          // Context pruning: trim old tool results without destroying conversation
          c.agents.defaults.contextPruning = c.agents.defaults.contextPruning || {};
          c.agents.defaults.contextPruning.mode = "cache-ttl";
          c.agents.defaults.contextPruning.ttl = "5m";
          // Message debounce: reduce duplicate processing from rapid messages
          c.messages = c.messages || {};
          c.messages.inbound = c.messages.inbound || {};
          c.messages.inbound.debounceMs = 1500;
          delete c.messages.inbound.perChannel;
          // Gateway: bind to loopback only (security)
          c.gateway = c.gateway || {};
          c.gateway.bind = "loopback";
          fs.writeFileSync(f, JSON.stringify(c, null, 2));
        '
        BASH;
    }

    private function buildEnvConfigPatchScript(Agent $agent, string $configFile): ?string
    {
        $envKeys = $this->collectLlmProviderEnvKeys($agent);

        if (empty($envKeys)) {
            return null;
        }

        $assignments = '';
        foreach ($envKeys as $key => $value) {
            $escapedValue = str_replace("'", "'\\''", $value);
            $assignments .= "c.env[\"{$key}\"] = \"{$escapedValue}\";\n          ";
        }

        return <<<BASH
        node -e '
          const fs = require("fs");
          const f = "{$configFile}";
          const c = JSON.parse(fs.readFileSync(f));
          c.env = c.env || {};
          {$assignments}fs.writeFileSync(f, JSON.stringify(c, null, 2));
        '
        BASH;
    }

    /**
     * Collect LLM provider API keys for the agent's team.
     *
     * @return array<string, string>
     */
    public function collectLlmProviderEnvKeys(Agent $agent): array
    {
        $team = $agent->server->team;
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
        // OpenRouter sub-keys work as auth for all providers via their API
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

    private function buildSlackPatchScript(Agent $agent, mixed $slack, string $configFile): string
    {
        $agentId = $agent->harness_agent_id;

        $channelAccounts = $this->configBuilder->collectAccounts($agent->server);
        $accountId = $this->configBuilder->resolveAccountId('slack', $agentId, $channelAccounts);

        // Base64-encode tokens to prevent bash/JS injection
        $encodedConfig = base64_encode(json_encode([
            'accountId' => $accountId,
            'agentId' => $agentId,
            'botToken' => $slack->bot_token,
            'appToken' => $slack->app_token,
        ]));

        return <<<BASH
        node -e '
          const fs = require("fs");
          const f = "{$configFile}";
          const c = JSON.parse(fs.readFileSync(f));
          const cfg = JSON.parse(Buffer.from("{$encodedConfig}", "base64").toString());
          c.channels = c.channels || {};
          c.channels.slack = c.channels.slack || { enabled: true, dmPolicy: "open", allowFrom: ["*"] };
          c.channels.slack.accounts = c.channels.slack.accounts || {};
          c.channels.slack.groupPolicy = "open";
          c.channels.slack.nativeStreaming = true;
          c.channels.slack.streaming = "partial";
          c.channels.slack.userTokenReadOnly = true;
          c.channels.slack.accounts[cfg.accountId] = {
            name: cfg.accountId,
            botToken: cfg.botToken,
            appToken: cfg.appToken,
            userTokenReadOnly: true,
            dmPolicy: "open",
            allowFrom: ["*"],
            nativeStreaming: true,
            streaming: "partial"
          };
          c.bindings = (c.bindings || []).filter(b => !(b.agentId === cfg.agentId && b.match && b.match.channel === "slack"));
          c.bindings.push({ agentId: cfg.agentId, match: { channel: "slack", accountId: cfg.accountId } });
          fs.writeFileSync(f, JSON.stringify(c, null, 2));
        '
        BASH;
    }

    private function buildEnableSlackPluginScript(string $configFile): string
    {
        return $this->buildEnableChannelPluginScript('slack', $configFile);
    }

    private function buildEnableChannelPluginScript(string $channel, string $configFile): string
    {
        return <<<BASH
        node -e '
          const fs = require("fs");
          const f = "{$configFile}";
          const c = JSON.parse(fs.readFileSync(f));
          c.plugins = c.plugins || {};
          c.plugins.entries = c.plugins.entries || {};
          c.plugins.entries.{$channel} = { enabled: true };
          fs.writeFileSync(f, JSON.stringify(c, null, 2));
        '
        BASH;
    }

    private function buildMailboxKitSkillDeployScript(Agent $agent): string
    {
        $emailConnection = $agent->emailConnection;
        $skillContent = file_get_contents(resource_path('skills/mailboxkit/SKILL.md'));

        // Bake agent-specific values directly into the skill file.
        // OpenClaw has no per-agent env, so $MAILBOXKIT_* shell vars won't resolve.
        $skillContent = str_replace('$MAILBOXKIT_API_KEY', config('mailboxkit.api_key'), $skillContent);
        $skillContent = str_replace('$MAILBOXKIT_INBOX_ID', (string) $emailConnection->mailboxkit_inbox_id, $skillContent);
        $skillContent = str_replace('$MAILBOXKIT_EMAIL', $emailConnection->email_address, $skillContent);

        // Deploy to agent's workspace skills directory (per-agent).
        // OpenClaw resolves workspace skills from <workspace>/skills/.
        $agentId = $agent->harness_agent_id;
        $skillDir = "/root/.openclaw/agents/{$agentId}/skills/mailboxkit";

        return implode("\n", [
            "mkdir -p {$skillDir}",
            $this->buildHeredoc("{$skillDir}/SKILL.md", $skillContent),
        ]);
    }

    /**
     * Build the HEARTBEAT.md content for an agent.
     *
     * Adds email checking if email is connected.
     */
    public static function buildHeartbeatContent(mixed $emailConnection = null): string
    {
        // Email checking is handled by check-email.sh (crontab, no LLM cost).
        // Heartbeat is reserved for lightweight periodic tasks only.
        return '';
    }

    /**
     * Generate the TOOLS.md workspace section.
     */
    /**
     * Generate the TOOLS.md environment variables section.
     */
    public static function envToolsMd(Agent $agent): string
    {
        $agentDir = "/root/.openclaw/agents/{$agent->harness_agent_id}";

        return implode("\n", [
            '## Environment Variables',
            '',
            "Your per-agent .env file is at `{$agentDir}/.env`.",
            '',
            '**IMPORTANT:** When you obtain API keys, tokens, or credentials for any service, store them in YOUR `.env` file — NOT in the shared openclaw.json or the system environment.',
            '',
            'To add a new key:',
            '```bash',
            "echo 'SERVICE_API_KEY=your-key-here' >> {$agentDir}/.env",
            '```',
            '',
            'To read a key in your scripts:',
            '```bash',
            "source {$agentDir}/.env && echo \$SERVICE_API_KEY",
            '```',
            '',
            'Never modify `/root/.openclaw/openclaw.json` directly. Never use `openclaw config` commands. Your .env file is private to you — other agents cannot see it.',
        ]);
    }

    public static function workspaceToolsMd(): string
    {
        return implode("\n", [
            '## Workspace',
            '',
            'Your current working directory IS your workspace. Your team can see all files here from the dashboard.',
            '',
            '**Reading:** Your team may upload reference documents, data files, and instructions here.',
            'Use `ls` to see available files, then read them as needed.',
            '',
            '**Writing:** Save all work output here — research reports, CSV exports, code files, analysis, drafts, etc.',
            'Organize output in subdirectories when appropriate (e.g., `./research/`, `./exports/`).',
            'Do NOT delete files uploaded by your team unless explicitly asked to.',
        ]);
    }

    /**
     * Generate the TOOLS.md GitHub/Git section for credential isolation.
     */
    public static function gitToolsMd(): string
    {
        return implode("\n", [
            '## GitHub & Git',
            '',
            '- You have your own isolated GitHub identity. The `gh` and `git` commands automatically use your credentials.',
            '- Run `gh auth status` to check if you are authenticated.',
            '- If not authenticated, create a GitHub account using the browser with your email, then run `gh auth login`.',
            '- Your git commits will use the name and email from your `.gitconfig`.',
        ]);
    }

    /**
     * Generate the browser profile name for an agent.
     */
    public static function browserProfileName(Agent $agent): string
    {
        return 'agent-'.strtolower(preg_replace('/[^a-zA-Z0-9]/', '-', $agent->name));
    }

    /**
     * Generate the TOOLS.md browser section instructing the agent to use its isolated profile.
     */
    public static function browserToolsMd(Agent $agent): string
    {
        $profileName = self::browserProfileName($agent);

        return implode("\n", [
            '## Browser',
            '',
            "You have your own isolated browser profile: `{$profileName}`.",
            'This gives you separate cookies, sessions, and login state from other agents.',
            '',
            '**IMPORTANT:** When using ANY browser tool, always pass `profile: "'.$profileName.'"` as a parameter.',
            'This ensures you use your own browser session and not the shared default.',
            '',
            'If you encounter a CAPTCHA or human verification that you cannot solve, tell your team member:',
            '"I need help with a verification step. Please check the Browser tab on my dashboard to take over."',
        ]);
    }

    /**
     * Generate the TOOLS.md email section for an agent with an email connection.
     */
    public static function emailToolsMd(mixed $emailConnection): string
    {
        $apiKey = config('mailboxkit.api_key');
        $inboxId = $emailConnection->mailboxkit_inbox_id;
        $email = $emailConnection->email_address;

        return implode("\n", [
            '## Email (MailboxKit)',
            '',
            "Your email address is **{$email}**.",
            '',
            '**IMPORTANT:** The ONLY way you can send and receive emails is through the MailboxKit API using the curl commands below.',
            'You do NOT have a dedicated email tool — you MUST use `exec` to run these curl commands. There is no other email method available to you.',
            '',
            '### Quick reference',
            '',
            '**List inbox messages:**',
            '```bash',
            "curl -sS -H \"Authorization: Bearer {$apiKey}\" -H \"Accept: application/json\" \\",
            "  \"https://mailboxkit.com/api/v1/inboxes/{$inboxId}/messages?per_page=10\" \\",
            '  | jq \'.data[] | {id, from: .from_email, subject, date: .created_at}\'',
            '```',
            '',
            '**Send an email:**',
            '```bash',
            "curl -sS -X POST -H \"Authorization: Bearer {$apiKey}\" \\",
            '  -H "Content-Type: application/json" -H "Accept: application/json" \\',
            "  \"https://mailboxkit.com/api/v1/inboxes/{$inboxId}/messages/send\" \\",
            '  -d \'{"to": ["recipient@example.com"], "subject": "Subject", "text": "Body", "html": "<p>Body</p>"}\'',
            '```',
            '',
            '**Reply to a message:**',
            '```bash',
            "curl -sS -X POST -H \"Authorization: Bearer {$apiKey}\" \\",
            '  -H "Content-Type: application/json" -H "Accept: application/json" \\',
            "  \"https://mailboxkit.com/api/v1/inboxes/{$inboxId}/messages/\$MESSAGE_ID/reply\" \\",
            '  -d \'{"text": "Reply text", "html": "<p>Reply</p>"}\'',
            '```',
            '',
            'Full reference with search, threads, and attachments: see your mailboxkit skill in the skills folder',
        ]);
    }

    private function buildMailboxKitSkillPatchScript(string $configFile): string
    {
        return <<<BASH
        node -e '
          const fs = require("fs");
          const f = "{$configFile}";
          const c = JSON.parse(fs.readFileSync(f));
          c.skills = c.skills || {};
          c.skills.entries = c.skills.entries || {};
          c.skills.entries["mailboxkit"] = { enabled: true };
          fs.writeFileSync(f, JSON.stringify(c, null, 2));
        '
        BASH;
    }

    /**
     * Build the bash script that creates per-agent Xvfb display, Chrome, x11vnc,
     * websockify systemd services, a Caddy route, and an OpenClaw browser profile.
     */
    private function buildBrowserDisplayScript(Agent $agent, string $configFile): string
    {
        $profileName = self::browserProfileName($agent);
        $chromeExec = config('openclaw.browser_executable_path', '/usr/bin/google-chrome-stable');
        $proxyPacFlag = app()->bound(AgentProxyProvider::class) ? ' --proxy-pac-url=http://127.0.0.1:8321/proxy.pac' : '';

        $isDocker = $agent->server?->isDocker();

        if ($isDocker) {
            // Docker: use shared display :99 from entrypoint, just start Chrome
            return <<<BASH
        export CDP_PORT=\$((9222 + \$(ls /root/.chrome-profiles/ 2>/dev/null | wc -l)))

        mkdir -p /root/.chrome-profiles/{$profileName}

        # Start Chrome on the shared display with its own profile
        export DISPLAY=:99
        nohup {$chromeExec} --no-sandbox --disable-gpu --no-first-run \
          --remote-debugging-port=\${CDP_PORT} \
          --user-data-dir=/root/.chrome-profiles/{$profileName} \
          --ozone-override-screen-size=1440,900 --window-size=1440,900{$proxyPacFlag} \
          > /dev/null 2>&1 &

        sleep 2

        # Register browser profile with cdpUrl in openclaw.json
        node -e '
          const fs = require("fs");
          const f = "{$configFile}";
          const c = JSON.parse(fs.readFileSync(f));
          c.browser = c.browser || {};
          c.browser.profiles = c.browser.profiles || {};
          const colors = ["#3b82f6","#ef4444","#10b981","#f59e0b","#8b5cf6","#ec4899","#06b6d4","#f97316"];
          const idx = Object.keys(c.browser.profiles).length;
          c.browser.profiles["{$profileName}"] = { cdpUrl: "http://127.0.0.1:" + process.env.CDP_PORT, color: colors[idx % colors.length] };
          fs.writeFileSync(f, JSON.stringify(c, null, 2));
        '
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
        export CDP_PORT=\$((9222 + DISPLAY_NUM))

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
        mkdir -p /etc/caddy/conf.d
        cat > /etc/caddy/conf.d/{$profileName}.caddy << CADDY_EOF
        handle_path /browser/{$profileName}/* {
            reverse_proxy localhost:\${WS_PORT}
        }
        CADDY_EOF
        systemctl reload caddy

        # Register browser profile with cdpUrl in openclaw.json
        node -e '
          const fs = require("fs");
          const f = "{$configFile}";
          const c = JSON.parse(fs.readFileSync(f));
          c.browser = c.browser || {};
          c.browser.profiles = c.browser.profiles || {};
          const colors = ["#3b82f6","#ef4444","#10b981","#f59e0b","#8b5cf6","#ec4899","#06b6d4","#f97316"];
          const idx = Object.keys(c.browser.profiles).length;
          c.browser.profiles["{$profileName}"] = { cdpUrl: "http://127.0.0.1:" + process.env.CDP_PORT, color: colors[idx % colors.length] };
          fs.writeFileSync(f, JSON.stringify(c, null, 2));
        '
        BASH;
    }

    private function buildHeredoc(string $filePath, string $content): string
    {
        // Single-quoted heredoc delimiter ('HEREDOC_EOF') prevents bash variable
        // expansion, so no need to escape $ signs in the content.
        return "cat > {$filePath} << 'HEREDOC_EOF'\n{$content}\nHEREDOC_EOF";
    }

    /**
     * Build the BOOTSTRAP.md content — a first-run onboarding document for the agent.
     *
     * Mimics an employee onboarding doc: introduces the role, lists accounts to set up,
     * and provides a checklist the agent works through on its first conversation.
     */
    public static function buildBootstrapContent(Agent $agent): string
    {
        $agent->loadMissing(['emailConnection', 'tools']);

        $lines = [];
        $lines[] = "# Welcome to the team, {$agent->name}!";
        $lines[] = '';
        $lines[] = 'This is your onboarding document. Read through it carefully and work through the checklist below.';
        $lines[] = 'Think of this as your first day — get yourself set up so you are ready to work.';
        $lines[] = '';

        // Role & job description
        if ($agent->job_description) {
            $lines[] = '## Your Role';
            $lines[] = '';
            $lines[] = $agent->job_description;
            $lines[] = '';
        }

        // Accounts & access setup
        $lines[] = '## Onboarding Checklist';
        $lines[] = '';
        $lines[] = 'Work through these items one by one. Ask your team for help when you need access or credentials.';
        $lines[] = '';

        $step = 1;

        // Always: introduce yourself
        $lines[] = "### {$step}. Introduce yourself";
        $lines[] = 'Send a brief hello to your team. Let them know you are online and ready to get set up.';
        $lines[] = '';
        $step++;

        // Email setup check
        $email = $agent->emailConnection?->email_address;
        if ($email) {
            $lines[] = "### {$step}. Verify your email";
            $lines[] = "Your email address is **{$email}**. Send yourself a test email to confirm it works.";
            $lines[] = '';
            $step++;
        }

        // GitHub setup
        $lines[] = "### {$step}. Set up GitHub";
        $lines[] = 'Run `gh auth status` to check if you are already authenticated.';
        $lines[] = 'If not, create a GitHub account using the browser and then run `gh auth login`.';
        $lines[] = '';
        $step++;

        // Browser check
        $lines[] = "### {$step}. Test the browser";
        $lines[] = 'Open a web page to confirm the browser works. Try searching for something related to your role.';
        $lines[] = '';
        $step++;

        // Tools setup with credentials
        $tools = $agent->tools;
        if ($tools->isNotEmpty()) {
            $lines[] = "### {$step}. Set up your tools";
            $lines[] = '';
            $lines[] = 'You need access to the following tools. For each one:';
            $lines[] = '1. Check your email for an invite from your team';
            $lines[] = '2. If no invite, go to the website and sign up using your email and password from IDENTITY.md';
            $lines[] = '3. Complete the onboarding for each tool';
            $lines[] = '';
            $lines[] = '| Tool | Website | Status |';
            $lines[] = '|------|---------|--------|';
            foreach ($tools as $tool) {
                $url = $tool->url ? "[{$tool->url}]({$tool->url})" : '---';
                $lines[] = "| {$tool->name} | {$url} | Pending |";
            }
            $lines[] = '';
            $lines[] = 'Let your team know which tools you still need invites for.';
            $lines[] = '';
            $step++;
        } elseif ($agent->job_description) {
            // Generic fallback when no explicit tools are defined
            $lines[] = "### {$step}. Review your job description and identify what you need";
            $lines[] = 'Read through your role description above. Think about:';
            $lines[] = '- What external services or accounts will you need access to?';
            $lines[] = '- What tools, APIs, or platforms are mentioned or implied?';
            $lines[] = '- What information do you need from your team to get started?';
            $lines[] = '';
            $lines[] = 'Ask your team to grant you access to anything you need. They can invite you via your email address.';
            $lines[] = '';
            $step++;
        }

        // Workspace exploration
        $lines[] = "### {$step}. Explore your workspace";
        $lines[] = 'Check if your team has uploaded any files or documents for you:';
        $lines[] = '```bash';
        $lines[] = 'ls -la';
        $lines[] = '```';
        $lines[] = 'Read any documents that look relevant to your role.';
        $lines[] = '';
        $step++;

        // Wrap up
        $lines[] = "### {$step}. Confirm you are ready";
        $lines[] = 'Once you have completed the steps above, let your team know:';
        $lines[] = '- What you have set up successfully';
        $lines[] = '- What access you still need';
        $lines[] = '- Any questions about your role';
        $lines[] = '';

        $lines[] = '---';
        $lines[] = '';
        $lines[] = '*Once onboarding is complete, you can delete this file: `rm BOOTSTRAP.md`*';

        return implode("\n", $lines);
    }

    /**
     * Append delegation instructions to the system prompt for channel agents.
     */
    public static function buildSystemPromptWithDelegation(Agent $agent): ?string
    {
        if (! $agent->system_prompt) {
            return null;
        }

        $systemPrompt = $agent->system_prompt;

        if ($agent->delegation_enabled) {
            $systemPrompt .= "\n\n## Task Delegation\n\n";
            $systemPrompt .= 'You have a `provision-tasks` skill that lets you create and delegate tasks to other agents on your team. ';
            $systemPrompt .= 'When someone asks you to assign, delegate, or create a task for another agent (e.g. "create a task for @max"), ';
            $systemPrompt .= "ALWAYS use the provision-tasks skill — never use the built-in spawn or sub-agent commands.\n\n";
            $systemPrompt .= "To delegate: `node {baseDir}/provision_tasks_tool.js create \"Task title\" --assign \"agent-name\"`\n";
            $systemPrompt .= "To see teammates: `node {baseDir}/provision_tasks_tool.js team-agents`\n";
        }

        return $systemPrompt;
    }

    /**
     * Build bash lines to deploy the provision-tasks skill for an agent.
     *
     * @param  callable(string, string): string  $buildHeredoc
     * @return list<string>
     */
    public static function buildProvisionTasksSkillLines(string $agentDir, string $plainToken, callable $buildHeredoc): array
    {
        $lines = [];
        $skillDir = "{$agentDir}/skills/provision-tasks";
        $lines[] = '# --- Deploy provision-tasks skill ---';
        $lines[] = "mkdir -p {$skillDir}";
        $lines[] = $buildHeredoc("{$skillDir}/SKILL.md", file_get_contents(resource_path('skills/provision-tasks/SKILL.md')));

        $toolScript = file_get_contents(resource_path('skills/provision-tasks/provision_tasks_tool.js'));
        $toolScript = str_replace(
            'const apiUrl = process.env.PROVISION_API_URL;',
            "const apiUrl = process.env.PROVISION_API_URL || '".config('app.url')."';",
            $toolScript,
        );
        $toolScript = str_replace(
            'const token = process.env.PROVISION_AGENT_TOKEN;',
            "const token = process.env.PROVISION_AGENT_TOKEN || '{$plainToken}';",
            $toolScript,
        );
        $lines[] = $buildHeredoc("{$skillDir}/provision_tasks_tool.js", $toolScript);
        $lines[] = $buildHeredoc("{$skillDir}/skill.json", file_get_contents(resource_path('skills/provision-tasks/skill.json')));

        return $lines;
    }

    /**
     * Ensure the agent has an API token, creating one if needed.
     * Returns the plaintext token for writing to the agent's .env.
     */
    public static function ensureAgentApiToken(Agent $agent): string
    {
        $existing = AgentApiToken::query()->where('agent_id', $agent->id)->first();

        // Reuse existing token if we can recover the plaintext
        if ($existing && $existing->token_encrypted) {
            return $existing->token_encrypted;
        }

        // Delete old token (no recoverable plaintext) and create fresh
        if ($existing) {
            $existing->delete();
        }

        $result = AgentApiToken::createForAgent($agent);

        return $result['plaintext'];
    }

    /**
     * Build the per-agent .env content with agent-specific credentials.
     */
    public static function buildAgentEnv(Agent $agent, ?string $apiToken = null): string
    {
        $agentDir = "/root/.openclaw/agents/{$agent->harness_agent_id}";

        $lines = [];

        // Provision Tasks API credentials
        $lines[] = 'PROVISION_API_URL='.config('app.url');
        if ($apiToken) {
            $lines[] = "PROVISION_AGENT_TOKEN={$apiToken}";
        }

        // Git/GitHub credential isolation
        $lines[] = "GH_CONFIG_DIR={$agentDir}/.gh";
        $lines[] = "GIT_CONFIG_GLOBAL={$agentDir}/.gitconfig";

        // MailboxKit credentials
        $agent->loadMissing('emailConnection');
        $emailConnection = $agent->emailConnection;
        if ($emailConnection?->mailboxkit_inbox_id) {
            $lines[] = 'MAILBOXKIT_API_KEY='.config('mailboxkit.api_key');
            $lines[] = "MAILBOXKIT_INBOX_ID={$emailConnection->mailboxkit_inbox_id}";
            $lines[] = "MAILBOXKIT_EMAIL={$emailConnection->email_address}";
        }

        return implode("\n", $lines);
    }

    private function buildTelegramPatchScript(Agent $agent, mixed $telegram, string $configFile): string
    {
        $agentId = $agent->harness_agent_id;
        $channelAccounts = $this->configBuilder->collectAccounts($agent->server);
        $accountId = $this->configBuilder->resolveAccountId('telegram', $agentId, $channelAccounts);

        $encodedConfig = base64_encode(json_encode([
            'accountId' => $accountId,
            'agentId' => $agentId,
            'botToken' => $telegram->bot_token,
        ]));

        return <<<BASH
        node -e '
          const fs = require("fs");
          const f = "{$configFile}";
          const c = JSON.parse(fs.readFileSync(f));
          const cfg = JSON.parse(Buffer.from("{$encodedConfig}", "base64").toString());
          c.channels = c.channels || {};
          c.channels.telegram = c.channels.telegram || { enabled: true, dmPolicy: "open", allowFrom: ["*"] };
          c.channels.telegram.accounts = c.channels.telegram.accounts || {};
          c.channels.telegram.accounts[cfg.accountId] = {
            name: cfg.accountId,
            botToken: cfg.botToken,
            dmPolicy: "open",
            allowFrom: ["*"]
          };
          c.bindings = (c.bindings || []).filter(b => !(b.agentId === cfg.agentId && b.match && b.match.channel === "telegram"));
          c.bindings.push({ agentId: cfg.agentId, match: { channel: "telegram", accountId: cfg.accountId } });
          fs.writeFileSync(f, JSON.stringify(c, null, 2));
        '
        BASH;
    }

    private function buildDiscordPatchScript(Agent $agent, mixed $discord, string $configFile): string
    {
        $agentId = $agent->harness_agent_id;
        $channelAccounts = $this->configBuilder->collectAccounts($agent->server);
        $accountId = $this->configBuilder->resolveAccountId('discord', $agentId, $channelAccounts);

        $encodedConfig = base64_encode(json_encode([
            'accountId' => $accountId,
            'agentId' => $agentId,
            'botToken' => $discord->token,
        ]));

        return <<<BASH
        node -e '
          const fs = require("fs");
          const f = "{$configFile}";
          const c = JSON.parse(fs.readFileSync(f));
          const cfg = JSON.parse(Buffer.from("{$encodedConfig}", "base64").toString());
          c.channels = c.channels || {};
          c.channels.discord = c.channels.discord || { enabled: true, dmPolicy: "open", allowFrom: ["*"] };
          c.channels.discord.accounts = c.channels.discord.accounts || {};
          c.channels.discord.accounts[cfg.accountId] = {
            name: cfg.accountId,
            botToken: cfg.botToken,
            dmPolicy: "open",
            allowFrom: ["*"]
          };
          c.bindings = (c.bindings || []).filter(b => !(b.agentId === cfg.agentId && b.match && b.match.channel === "discord"));
          c.bindings.push({ agentId: cfg.agentId, match: { channel: "discord", accountId: cfg.accountId } });
          fs.writeFileSync(f, JSON.stringify(c, null, 2));
        '
        BASH;
    }

    private function buildEnvScript(Agent $agent): string
    {
        $team = $agent->server->team;
        $activeKeys = $team->apiKeys()->where('is_active', true)->get();
        $envLines = [];

        foreach ($activeKeys as $apiKey) {
            $envLines[] = "{$apiKey->provider->envKeyName()}={$apiKey->api_key}";
        }

        // If team has OpenRouter but no native OpenAI key, alias it for embedding auth
        $hasOpenAi = $activeKeys->contains('provider', LlmProvider::OpenAi);
        $openRouterKey = $activeKeys->firstWhere('provider', LlmProvider::OpenRouter);

        if (! $hasOpenAi && $openRouterKey) {
            $envLines[] = "OPENAI_API_KEY={$openRouterKey->api_key}";
        }

        // Add managed API key if no user-provided OpenRouter key exists
        $managedKey = $team->managedApiKey;
        $hasAnthropic2 = $activeKeys->contains('provider', LlmProvider::Anthropic);
        if ($managedKey && ! $activeKeys->contains('provider', LlmProvider::OpenRouter)) {
            $envLines[] = "OPENROUTER_API_KEY={$managedKey->api_key}";

            if (! $hasOpenAi) {
                $envLines[] = "OPENAI_API_KEY={$managedKey->api_key}";
            }

            if (! $hasAnthropic2) {
                $envLines[] = "ANTHROPIC_API_KEY={$managedKey->api_key}";
            }
        }

        foreach ($team->envVars as $envVar) {
            $envLines[] = "{$envVar->key}={$envVar->value}";
        }

        $envContent = implode("\n", $envLines);

        return "cat > /root/.openclaw/.env << 'ENV_EOF'\n{$envContent}\nENV_EOF";
    }
}
