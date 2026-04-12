<?php

namespace App\Services\Harness;

use App\Contracts\CommandExecutor;
use App\Contracts\HarnessDriver;
use App\Enums\AgentStatus;
use App\Enums\LlmProvider;
use App\Events\AgentUpdatedEvent;
use App\Jobs\RestartGatewayJob;
use App\Jobs\SetupAgentGitHubJob;
use App\Jobs\VerifyAgentChannelsJob;
use App\Mail\AgentActiveMail;
use App\Models\Agent;
use App\Models\Server;
use App\Services\AgentInstallScriptService;
use App\Services\AgentScheduleService;
use App\Services\ChannelConfigBuilder;
use App\Services\ConfigPatchService;
use App\Services\Scripts\AgentUpdateScriptService;
use App\Support\OpenClawConfig;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class OpenClawDriver implements HarnessDriver
{
    public function __construct(
        private AgentInstallScriptService $scriptService,
        private AgentScheduleService $scheduleService,
        private ChannelConfigBuilder $configBuilder,
        private ConfigPatchService $configPatchService,
    ) {}

    public function setupOnServer(Server $server, CommandExecutor $executor): void
    {
        // OpenClaw setup (onboard, configureDefaults, installByteRover, etc.) runs
        // during server provisioning via SetupOpenClawOnServerJob. Nothing to do here.
        Log::info("OpenClaw already configured on server {$server->id} during provisioning");
    }

    public function createAgent(Agent $agent, CommandExecutor $executor): void
    {
        // For Docker, generate script directly (avoids HTTP callback to self).
        // For SSH, use signed URL (one SSH call downloads + executes).
        if ($agent->server?->isDocker()) {
            $script = $this->scriptService->generateScript($agent);
            $executor->execScript($script);
        } else {
            $scriptUrl = $this->scriptService->buildSignedUrl($agent);
            $executor->execScript($scriptUrl);
        }

        $this->verifyAndActivate($agent, $executor);

        // Create default cron jobs (email check if connected) for the new agent
        if ($agent->fresh()->status === AgentStatus::Active) {
            // Refresh relations — email connection may have been created during install script
            $agent->load('emailConnection');
            $this->scheduleService->createDefaultCrons($agent, $executor);

            // When adding a second+ agent, rebuild ALL channel configs to fix
            // stale account IDs (e.g. "default" → "slack-{agentId}") on existing agents
            $this->rebuildAllChannelConfigs($agent->server, $executor);

            // Verify channel config is correct after activation
            VerifyAgentChannelsJob::dispatch($agent)->delay(now()->addSeconds(60));

            // Dispatch GitHub setup if agent has an email address for verification
            if ($agent->emailConnection?->email_address) {
                SetupAgentGitHubJob::dispatch($agent)->delay(now()->addSeconds(30));
            }
        }
    }

    public function updateAgent(Agent $agent, CommandExecutor $executor): void
    {
        $updateService = app(AgentUpdateScriptService::class);
        if ($agent->server?->isDocker()) {
            $script = $updateService->generateOpenClawScript($agent);
            $executor->execScript($script);
        } else {
            $scriptUrl = $updateService->buildSignedUrl($agent);
            $executor->execScript($scriptUrl);
        }

        // Store config snapshot for future reference
        $agent->update([
            'config_snapshot' => $updateService->buildOpenClawConfigSnapshot($agent),
            'is_syncing' => false,
            'last_synced_at' => now(),
        ]);

        if ($agent->status === AgentStatus::Deploying) {
            $agent->update(['status' => AgentStatus::Active]);
        }

        broadcast(new AgentUpdatedEvent($agent));
    }

    /**
     * @deprecated Kept for reference — replaced by AgentUpdateScriptService
     */
    private function _legacyUpdateAgent(Agent $agent, CommandExecutor $executor): void
    {
        $server = $agent->server;
        $configPath = '/root/.openclaw/openclaw.json';
        $agentId = $agent->harness_agent_id;
        $agentDir = $this->agentDir($agent);

        // Read current config
        $config = json_decode($executor->readFile($configPath), true);

        // Ensure restrictive "messaging" tool profile is removed — unset means
        // "full" (all tools available). The sandbox deny list handles restrictions.
        unset($config['tools']['profile']);

        // Update or add agent entry
        $agentEntry = [
            'id' => $agentId,
            'name' => $agent->name,
            'workspace' => $agentDir,
            'agentDir' => "{$agentDir}/agent",
            'model' => $agent->openclawModelConfig(),
        ];

        $agents = $config['agents']['list'] ?? [];
        $index = array_search($agentId, array_column($agents, 'id'));

        if ($index !== false) {
            $agents[$index] = array_merge($agents[$index], $agentEntry);
        } else {
            $agents[] = $agentEntry;
        }

        $config['agents']['list'] = array_values($agents);

        // Use the cheapest model for heartbeats with light context to reduce token usage
        $config['agents']['defaults'] = $config['agents']['defaults'] ?? [];
        $config['agents']['defaults']['heartbeat'] = $config['agents']['defaults']['heartbeat'] ?? [];
        $config['agents']['defaults']['heartbeat']['model'] = LlmProvider::AUTOMATION_MODEL;
        $config['agents']['defaults']['heartbeat']['lightContext'] = true;

        // Rebuild all channel accounts and bindings from database
        $this->configBuilder->applyToConfig($config, $server);

        // Add MailboxKit email skill config if agent has email connection
        $emailConnection = $agent->emailConnection;
        if ($emailConnection?->mailboxkit_inbox_id) {
            $config['skills'] = $config['skills'] ?? [];
            $config['skills']['entries'] = $config['skills']['entries'] ?? [];
            $config['skills']['entries']['mailboxkit'] = ['enabled' => true];
        }

        // Clean up legacy env vars that should NOT be in shared env
        foreach (['MAILBOXKIT_API_KEY', 'MAILBOXKIT_INBOX_ID', 'MAILBOXKIT_EMAIL', 'GH_CONFIG_DIR', 'GIT_CONFIG_GLOBAL', 'PROVISION_API_URL', 'PROVISION_AGENT_TOKEN'] as $key) {
            unset($config['env'][$key]);
        }

        // Remove invalid top-level keys that crash the gateway
        unset($config['config']);

        // Enable provision-tasks skill (core, always deployed)
        $config['skills'] = $config['skills'] ?? [];
        $config['skills']['entries'] = $config['skills']['entries'] ?? [];
        $config['skills']['entries']['provision-tasks'] = ['enabled' => true];

        // Write updated config
        $executor->writeFile($configPath, OpenClawConfig::toJson($config));

        // Update workspace files and directories
        $executor->exec("mkdir -p {$agentDir}");
        // Migrate old knowledge/ dir to workspace/ if it exists
        $executor->exec("test -d {$agentDir}/knowledge && ! -d {$agentDir}/workspace && mv {$agentDir}/knowledge {$agentDir}/workspace || true");
        $executor->exec("mkdir -p {$agentDir}/workspace");

        // Create isolated Git/GitHub config directory
        $executor->exec("mkdir -p {$agentDir}/.gh");

        // Seed .gitconfig if empty or missing
        $existingGitconfig = '';
        try {
            $existingGitconfig = $executor->readFile("{$agentDir}/.gitconfig");
        } catch (\RuntimeException) {
            // File doesn't exist yet
        }

        if (empty(trim($existingGitconfig))) {
            $email = $agent->emailConnection?->email_address
                ?? "{$agentId}@noreply.openclaw.ai";
            $executor->writeFile("{$agentDir}/.gitconfig",
                "[user]\n    name = {$agent->name}\n    email = {$email}\n");
        }

        if ($agent->soul) {
            $executor->writeFile("{$agentDir}/SOUL.md", $agent->soul);
        }

        if ($agent->system_prompt) {
            $executor->writeFile("{$agentDir}/AGENTS.md", $agent->system_prompt);
        }

        if ($agent->identity) {
            $executor->writeFile("{$agentDir}/IDENTITY.md", $agent->identity);
        }

        if ($agent->user_context) {
            $executor->writeFile("{$agentDir}/USER.md", $agent->user_context);
        }

        // Write BOOTSTRAP.md: first-run onboarding checklist (only if it doesn't exist yet)
        $bootstrapExists = false;
        try {
            $executor->readFile("{$agentDir}/BOOTSTRAP.md");
            $bootstrapExists = true;
        } catch (\RuntimeException) {
            // File doesn't exist yet
        }
        if (! $bootstrapExists) {
            $executor->writeFile("{$agentDir}/BOOTSTRAP.md", AgentInstallScriptService::buildBootstrapContent($agent));
        }

        // Write HEARTBEAT.md: periodic checks (task polling always, email if connected)
        $executor->writeFile("{$agentDir}/HEARTBEAT.md", AgentInstallScriptService::buildHeartbeatContent($emailConnection));

        // Write TOOLS.md: merge tools_config with email info
        $toolsMd = $agent->tools_config ?? '';
        if ($emailConnection?->mailboxkit_inbox_id) {
            $this->deployMailboxKitSkill($agent, $executor);
            $this->deployEmailCheckScript($agent, $executor);

            $toolsMd .= "\n\n".AgentInstallScriptService::emailToolsMd($emailConnection);
        }

        $toolsMd .= "\n\n".AgentInstallScriptService::workspaceToolsMd();
        $toolsMd .= "\n\n".AgentInstallScriptService::gitToolsMd();
        $toolsMd .= "\n\n".AgentInstallScriptService::browserToolsMd($agent);

        // Deploy provision-tasks skill (core, always deployed)
        $this->deployTasksSkill($agent, $executor);

        // Ensure agent has an API token for the tasks API
        $plainToken = AgentInstallScriptService::ensureAgentApiToken($agent);

        // Write per-agent .env with agent-specific credentials (tasks API + mailboxkit)
        $executor->writeFile("{$agentDir}/.env", AgentInstallScriptService::buildAgentEnv($agent, $plainToken));
        if (trim($toolsMd)) {
            $executor->writeFile("{$agentDir}/TOOLS.md", trim($toolsMd));
        }

        // Snapshot config, clear sync flag, activate if still deploying, and restart
        $updateData = [
            'config_snapshot' => $config,
            'is_syncing' => false,
            'last_synced_at' => now(),
        ];

        if ($agent->status === AgentStatus::Deploying) {
            $updateData['status'] = AgentStatus::Active;
        }

        $agent->update($updateData);
        broadcast(new AgentUpdatedEvent($agent));

        RestartGatewayJob::dispatch($server);
    }

    public function removeAgent(Agent $agent, CommandExecutor $executor): void
    {
        $openclawAgentId = $agent->harness_agent_id;
        $server = $agent->server;
        $hasSlack = $agent->slackConnection()->exists();

        $executor->exec($this->configPatchService->buildRemoveAgentPatch($openclawAgentId));

        if ($hasSlack) {
            $executor->exec($this->configPatchService->buildRemoveSlackTokensPatch());
        }

        $agentDir = $this->agentDir($agent);
        $executor->exec("rm -rf {$agentDir}");

        RestartGatewayJob::dispatch($server);
    }

    public function restartGateway(Server $server, CommandExecutor $executor): void
    {
        if ($server->isDocker()) {
            // Docker: kill and respawn (no systemd)
            try {
                $executor->exec('pkill -f "openclaw gateway" 2>/dev/null; exit 0');
            } catch (\RuntimeException) {
                // pkill exit codes are non-zero when signal is delivered — expected
            }
            sleep(2);
            $executor->exec('export DISPLAY=:99 && nohup openclaw gateway >> /root/.openclaw/logs/gateway.log 2>&1 &');
        } else {
            $executor->execWithRetry('export XDG_RUNTIME_DIR=/run/user/$(id -u) && systemctl --user restart openclaw-gateway');
        }

        sleep(5);

        $healthy = $this->checkGatewayHealth($executor);

        if (! $healthy) {
            sleep(10);
            $healthy = $this->checkGatewayHealth($executor);
        }

        // Restart the managed browser service (it doesn't auto-start with the gateway)
        $this->startBrowserService($server, $executor);

        $server->events()->create([
            'event' => $healthy ? 'gateway_restarted' : 'gateway_restart_unhealthy',
            'payload' => ['healthy' => $healthy],
        ]);
    }

    public function checkHealth(Agent $agent, CommandExecutor $executor): bool
    {
        return $this->checkGatewayHealth($executor);
    }

    public function agentDir(Agent $agent): string
    {
        return "/root/.openclaw/agents/{$agent->harness_agent_id}";
    }

    /**
     * @return string|array{primary: string, fallbacks: list<string>}
     */
    public function formatModelConfig(Agent $agent): string|array
    {
        return $agent->openclawModelConfig();
    }

    /**
     * Fallback activation — skip if the install script's callback already set the status.
     */
    private function verifyAndActivate(Agent $agent, CommandExecutor $executor): void
    {
        // The install script fires a callback to set status. Give it a moment, then check.
        sleep(5);

        if ($agent->fresh()->status === AgentStatus::Active) {
            Log::info("Agent {$agent->id} already activated via callback");

            return;
        }

        // Verify agent entry exists in openclaw.json before activating
        if (! $this->verifyAgentInConfig($agent, $executor)) {
            Log::error("Agent {$agent->id} not found in openclaw.json after install script");
            $agent->update(['status' => AgentStatus::Error]);
            broadcast(new AgentUpdatedEvent($agent));

            return;
        }

        // Config is good — check if gateway is healthy
        if ($this->checkGatewayHealth($executor)) {
            $this->activateAgent($agent);

            return;
        }

        // Retry once
        sleep(10);

        if ($agent->fresh()->status === AgentStatus::Active) {
            return;
        }

        if ($this->checkGatewayHealth($executor)) {
            $this->activateAgent($agent);

            return;
        }

        Log::warning("Agent {$agent->id} deployed but gateway health check failed — config verified, marking active");

        // Config is verified on the server, gateway may just need time to warm up
        $this->activateAgent($agent, healthWarning: true);
    }

    /**
     * Verify the agent entry was written to openclaw.json on the server.
     */
    private function verifyAgentInConfig(Agent $agent, CommandExecutor $executor): bool
    {
        try {
            $agentId = $agent->harness_agent_id;
            $output = $executor->exec("node -e 'const c = JSON.parse(require(\"fs\").readFileSync(\"/root/.openclaw/openclaw.json\")); const found = (c.agents?.list || []).some(a => a.id === \"{$agentId}\"); console.log(found ? \"FOUND\" : \"MISSING\");'");

            return str_contains($output, 'FOUND');
        } catch (\RuntimeException $e) {
            Log::warning("Config verification failed for agent {$agent->id}: {$e->getMessage()}");

            return false;
        }
    }

    private function checkGatewayHealth(CommandExecutor $executor): bool
    {
        try {
            $health = $executor->exec('openclaw health 2>&1');

            return str_contains($health, 'ok') || str_contains($health, 'healthy');
        } catch (\RuntimeException) {
            return false;
        }
    }

    /**
     * Rebuild all channel configs when multiple agents share the server.
     */
    private function rebuildAllChannelConfigs(Server $server, CommandExecutor $executor): void
    {
        $agentCount = $server->agents()->whereNotNull('harness_agent_id')->count();

        if ($agentCount < 2) {
            return;
        }

        try {
            $configJson = $executor->readFile('/root/.openclaw/openclaw.json');
            $config = json_decode($configJson, true);

            if (! $config) {
                Log::warning("Cannot rebuild channel configs — failed to parse openclaw.json for server {$server->id}");

                return;
            }

            $this->configBuilder->applyToConfig($config, $server);

            $executor->writeFile(
                '/root/.openclaw/openclaw.json',
                OpenClawConfig::toJson($config)
            );

            // Restart gateway to pick up new config
            $executor->exec('export XDG_RUNTIME_DIR=/run/user/$(id -u) && systemctl --user restart openclaw-gateway 2>&1 || true');

            Log::info("Rebuilt all channel configs for server {$server->id} ({$agentCount} agents)");
        } catch (\RuntimeException $e) {
            Log::warning("Failed to rebuild channel configs for server {$server->id}: {$e->getMessage()}");
        }
    }

    private function activateAgent(Agent $agent, bool $healthWarning = false): void
    {
        $agent->update([
            'status' => AgentStatus::Active,
        ]);
        broadcast(new AgentUpdatedEvent($agent));

        $payload = ['agent_id' => $agent->id];
        if ($healthWarning) {
            $payload['health_warning'] = true;
        }

        $agent->server->events()->create([
            'event' => 'agent_install_complete',
            'payload' => $payload,
        ]);

        Mail::to($agent->team->owner->email)->send(new AgentActiveMail($agent));
    }

    private function startBrowserService(Server $server, CommandExecutor $executor): void
    {
        try {
            $executor->exec('openclaw browser --browser-profile openclaw start 2>&1');
        } catch (\RuntimeException $e) {
            Log::warning("Failed to start browser service on server {$server->id}: {$e->getMessage()}");
        }
    }

    private function deployMailboxKitSkill(Agent $agent, CommandExecutor $executor): void
    {
        $emailConnection = $agent->emailConnection;
        $skillContent = file_get_contents(resource_path('skills/mailboxkit/SKILL.md'));

        // Bake agent-specific values directly into the skill file.
        $skillContent = str_replace('$MAILBOXKIT_API_KEY', config('mailboxkit.api_key'), $skillContent);
        $skillContent = str_replace('$MAILBOXKIT_INBOX_ID', (string) $emailConnection->mailboxkit_inbox_id, $skillContent);
        $skillContent = str_replace('$MAILBOXKIT_EMAIL', $emailConnection->email_address, $skillContent);

        // Deploy per-agent skill to the agent's workspace skills directory.
        $agentDir = $this->agentDir($agent);
        $skillDir = "{$agentDir}/skills/mailboxkit";
        $executor->exec("mkdir -p {$skillDir}");
        $executor->writeFile("{$skillDir}/SKILL.md", $skillContent);
    }

    private function deployTasksSkill(Agent $agent, CommandExecutor $executor): void
    {
        $agentDir = $this->agentDir($agent);
        $skillDir = "{$agentDir}/skills/provision-tasks";
        $executor->exec("mkdir -p {$skillDir}");

        $executor->writeFile(
            "{$skillDir}/SKILL.md",
            file_get_contents(resource_path('skills/provision-tasks/SKILL.md')),
        );

        $executor->writeFile(
            "{$skillDir}/provision_tasks_tool.js",
            file_get_contents(resource_path('skills/provision-tasks/provision_tasks_tool.js')),
        );
    }

    private function deployEmailCheckScript(Agent $agent, CommandExecutor $executor): void
    {
        $agentId = $agent->harness_agent_id;
        $agentDir = $this->agentDir($agent);

        $script = AgentScheduleService::buildEmailCheckScript($agentId, $agentDir);
        $script = str_replace(['__AGENT_DIR__', '__AGENT_ID__'], [$agentDir, $agentId], $script);
        $executor->writeFile("{$agentDir}/check-email.sh", $script);
        $executor->exec("chmod +x {$agentDir}/check-email.sh");

        // Install crontab entry (idempotent)
        $marker = "# provision-email-check-{$agentId}";
        $cronLine = "*/5 * * * * {$agentDir}/check-email.sh >> {$agentDir}/email-check.log 2>&1 {$marker}";
        $executor->exec("(crontab -l 2>/dev/null | grep -v '{$marker}'; echo '{$cronLine}') | crontab -");
    }
}
