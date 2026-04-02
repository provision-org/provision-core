<?php

namespace App\Jobs;

use App\Enums\CloudProvider;
use App\Enums\ServerStatus;
use App\Models\Server;
use App\Services\CloudServiceFactory;
use App\Services\DigitalOceanService;
use App\Services\HarnessManager;
use App\Services\HetznerService;
use App\Services\LinodeService;
use App\Services\OpenClawDefaultsService;
use App\Services\Scripts\ServerSetupScriptService;
use App\Services\SshService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class SetupOpenClawOnServerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 900;

    public function __construct(public Server $server) {}

    public function handle(HarnessManager $harnessManager, ServerSetupScriptService $scriptService): void
    {
        // The provider may not include the IP in the initial create response.
        // Fetch it from the API before attempting SSH if it's missing.
        if (! $this->server->ipv4_address && $this->server->provider_server_id) {
            $this->fetchAndSaveIpAddress();
        }

        // Generate a signed URL for the setup script.
        // The script handles ALL setup: onboard, config, VNC, gateway, health checks.
        // Progress callbacks fire back to /api/webhooks/server-setup.
        // The final "ready" callback sets the server to Running.
        $scriptUrl = $scriptService->buildSignedUrl($this->server);

        $executor = $harnessManager->resolveExecutor($this->server);

        try {
            // One SSH call replaces 20+ individual operations.
            // The script runs locally on the server and fires callbacks for progress.
            $executor->execScript($scriptUrl);

            // If we reach here, the script exited successfully (exit 0).
            // The script's "ready" callback already set the server to Running.
            // But verify just in case the callback didn't fire (local dev without herd share).
            $this->server->refresh();
            if ($this->server->status !== ServerStatus::Running) {
                $this->server->update([
                    'status' => ServerStatus::Running,
                    'provisioned_at' => now(),
                ]);

                $this->server->events()->create([
                    'event' => 'setup_complete',
                    'payload' => ['source' => 'job_fallback'],
                ]);
            }

            UpdateEnvOnServerJob::dispatch($this->server);
        } finally {
            if ($executor instanceof SshService) {
                $executor->disconnect();
            }
        }
    }

    public function failed(Throwable $exception): void
    {
        $this->server->update(['status' => ServerStatus::Error]);

        $this->server->events()->create([
            'event' => 'setup_failed',
            'payload' => ['error' => $exception->getMessage()],
        ]);
    }

    private function fetchAndSaveIpAddress(): void
    {
        $ip = match ($this->server->cloud_provider) {
            CloudProvider::DigitalOcean => $this->fetchDigitalOceanIp(),
            CloudProvider::Linode => $this->fetchLinodeIp(),
            default => $this->fetchHetznerIp(),
        };

        if ($ip) {
            $this->server->update(['ipv4_address' => $ip]);
            $this->server->refresh();
            Log::info("Fetched IP {$ip} from {$this->server->cloud_provider->label()} for server {$this->server->id}");
        } else {
            throw new \RuntimeException("Could not fetch IP address from {$this->server->cloud_provider->label()} for server {$this->server->id}");
        }
    }

    private function fetchHetznerIp(): ?string
    {
        /** @var HetznerService $hetzner */
        $hetzner = app(CloudServiceFactory::class)->makeFor($this->server->team, CloudProvider::Hetzner);
        $response = $hetzner->getServer($this->server->provider_server_id);

        return $response['server']['public_net']['ipv4']['ip'] ?? null;
    }

    private function fetchDigitalOceanIp(): ?string
    {
        /** @var DigitalOceanService $doService */
        $doService = app(CloudServiceFactory::class)->makeFor($this->server->team, CloudProvider::DigitalOcean);
        $response = $doService->getDroplet($this->server->provider_server_id);

        return $doService->extractIpAddress($response['droplet'] ?? []);
    }

    private function fetchLinodeIp(): ?string
    {
        /** @var LinodeService $linodeService */
        $linodeService = app(CloudServiceFactory::class)->makeFor($this->server->team, CloudProvider::Linode);
        $instance = $linodeService->getInstance($this->server->provider_server_id);

        return $linodeService->extractIpAddress($instance);
    }

    private function captureOpenClawVersion(SshService $sshService): void
    {
        try {
            $output = trim($sshService->exec('openclaw --version'));
            // Output is like "openclaw v2026.3.8" — extract the version part
            $version = Str::after($output, 'openclaw ');
            if ($version) {
                $this->server->update(['openclaw_version' => $version]);
                Log::info("OpenClaw {$version} installed on server {$this->server->id}");
            }
        } catch (\RuntimeException $e) {
            Log::warning("Could not determine OpenClaw version on server {$this->server->id}: {$e->getMessage()}");
        }
    }

    private function logProgress(string $step): void
    {
        $this->server->events()->create([
            'event' => 'setup_progress',
            'payload' => ['step' => $step],
        ]);
    }

    private function configureOpenClawDefaults(SshService $sshService, OpenClawDefaultsService $defaultsService): void
    {
        $configPath = '/root/.openclaw/openclaw.json';

        try {
            $config = json_decode($sshService->readFile($configPath), true) ?? [];
        } catch (\RuntimeException $e) {
            Log::warning("Config file not found after onboard on server {$this->server->id}, creating default config");
            $config = [];
        }

        // Initialize bindings array for multi-agent channel routing
        $config['bindings'] = [];

        // Remove legacy single-account channel configs set by `openclaw onboard` —
        // our UpdateAgentOnServerJob rebuilds per-account configs from the database
        foreach (['slack', 'telegram', 'discord'] as $channel) {
            unset($config['channels'][$channel]);
        }

        // Sandbox disabled — managed browser handles browsing on the host directly.
        // Sandbox docker.env doesn't reliably inject env vars, so we run without it.
        $config['agents'] = $config['agents'] ?? [];
        $config['agents']['defaults'] = $config['agents']['defaults'] ?? [];
        $config['agents']['defaults']['sandbox'] = ['mode' => 'off'];

        // Managed browser — each agent gets its own Chrome instance on a dedicated virtual
        // display (created by the agent install script). Full Chrome (not Chromium) avoids
        // CAPTCHA/bot detection. noSandbox required when running as root.
        // No defaultProfile is set — agents specify their own profile via TOOLS.md instructions.
        $config['browser'] = $config['browser'] ?? [];
        $config['browser']['enabled'] = true;
        $config['browser']['headless'] = false;
        $config['browser']['noSandbox'] = true;
        $config['browser']['executablePath'] = config('openclaw.browser_executable_path');

        // Typing indicator — show "typing..." in channels while agent is thinking
        $config['agents']['defaults']['typingMode'] = 'thinking';

        // Light context for heartbeats — skip large bootstrap files to reduce token usage
        $config['agents']['defaults']['heartbeat'] = $config['agents']['defaults']['heartbeat'] ?? [];
        $config['agents']['defaults']['heartbeat']['lightContext'] = true;

        // Remove restrictive "messaging" tool profile set by `openclaw onboard` —
        // unset means "full" (all tools available). The deny list below
        // handles restricting dangerous channel/automation tools.
        $config['tools'] = $config['tools'] ?? [];
        unset($config['tools']['profile']);

        // Tool policy — deny-only list to prevent agents from controlling channels directly
        $config['tools']['deny'] = ['canvas', 'nodes', 'gateway', 'config', 'system', 'telegram', 'whatsapp', 'discord', 'irc', 'googlechat', 'slack', 'signal', 'imessage'];

        if (! isset($config['plugins']) || ! is_array($config['plugins']) || array_is_list($config['plugins'])) {
            $config['plugins'] = [];
        }
        if (! isset($config['plugins']['entries']) || ! is_array($config['plugins']['entries']) || array_is_list($config['plugins']['entries'])) {
            $config['plugins']['entries'] = [];
        }
        // Remove broken diffs plugin (missing @pierre/diffs module crashes CLI)
        unset($config['plugins']['entries']['diffs']);

        // ByteRover — persistent, per-agent memory.
        // Curates knowledge after each turn, retrieves relevant context before each prompt.
        // No `cwd` — defaults to process.cwd() which OpenClaw sets to the agent's workspace,
        // ByteRover — persistent, per-agent memory.
        // Only set as context engine if the plugin was successfully installed.
        // The installByteRover() method handles plugin installation; if it fails
        // (e.g. rate-limited by ClawHub), we skip the config to avoid crashing the gateway.
        // The plugin entry is added by installByteRover() only on success.

        // Skills — watch for new/updated SKILL.md files so agents pick them up mid-session
        $config['skills'] = $config['skills'] ?? [];
        $config['skills']['load'] = $config['skills']['load'] ?? [];
        $config['skills']['load']['watch'] = true;

        // Loop detection — prevent agents from repeating failed tool calls
        $config['tools']['loopDetection'] = [
            'enabled' => true,
            'historySize' => 30,
            'warningThreshold' => 10,
            'criticalThreshold' => 20,
        ];

        // Efficient snapshot mode to reduce token usage for browser interactions
        $config['browser']['snapshotDefaults'] = ['mode' => 'efficient'];

        // Default viewport — 1440×900 gives a full desktop layout for screenshots
        $config['browser']['extraArgs'] = ['--ozone-override-screen-size=1440,900', '--window-size=1440,900'];

        // Session management — multi-user isolation and cleanup
        $config['session'] = $config['session'] ?? [];
        $config['session']['dmScope'] = 'per-channel-peer';
        $config['session']['reset'] = [
            'mode' => 'idle',
            'idleMinutes' => 120,
        ];
        $config['session']['maintenance'] = [
            'pruneAfter' => '14d',
            'maxEntries' => 500,
            'maxDiskBytes' => '500mb',
        ];

        // Message queue — debounce rapid messages into a single turn
        $config['messages'] = $config['messages'] ?? [];
        $config['messages']['queue'] = [
            'mode' => 'collect',
            'debounceMs' => 2000,
        ];
        $config['messages']['inbound'] = [
            'debounceMs' => 2000,
        ];

        // Redact sensitive data (API keys, tokens) from tool output logs
        $config['logging'] = $config['logging'] ?? [];
        $config['logging']['redactSensitive'] = 'tools';

        // Agent defaults from service
        $defaults = $defaultsService->buildDefaults($this->server);
        $config['agents']['defaults'] = array_replace_recursive(
            $config['agents']['defaults'],
            $defaults,
        );

        // Ensure object-type keys encode as {} not [] when empty
        $objectKeys = ['plugins', 'plugins.entries', 'channels', 'skills', 'skills.entries', 'skills.load', 'env', 'tools'];
        foreach ($objectKeys as $path) {
            $parts = explode('.', $path);
            $ref = &$config;
            foreach ($parts as $part) {
                if (! isset($ref[$part])) {
                    break;
                }
                $ref = &$ref[$part];
            }
            if (is_array($ref) && empty($ref)) {
                $ref = (object) [];
            }
            unset($ref);
        }

        $sshService->writeFile($configPath, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function installByteRover(SshService $sshService): void
    {
        try {
            // Install the OpenClaw plugin (per-agent brv init happens in agent install script)
            $sshService->exec('export XDG_RUNTIME_DIR=/run/user/$(id -u) && openclaw plugins install @byterover/byterover 2>&1 || true');

            Log::info("ByteRover installed on server {$this->server->id}");
        } catch (\RuntimeException $e) {
            // Non-fatal — agents work fine without memory, just without persistence
            Log::warning("ByteRover install failed on server {$this->server->id}: {$e->getMessage()}");
        }
    }

    private function setupBrowserDisplay(SshService $sshService): void
    {
        $vncPassword = Str::random(16);
        $this->server->forceFill(['vnc_password' => $vncPassword])->save();

        // Set up VNC password file for x11vnc (shared by all per-agent VNC services)
        $sshService->exec('mkdir -p /root/.vnc');
        $sshService->exec("x11vnc -storepasswd '{$vncPassword}' /root/.vnc/passwd");

        // Xvfb — virtual framebuffer at display :99 as a fallback.
        // Per-agent displays (:1, :2, ...) are created by the agent install script.
        $sshService->writeFile('/etc/systemd/system/xvfb.service', <<<'UNIT'
        [Unit]
        Description=Xvfb virtual framebuffer
        After=network.target

        [Service]
        ExecStart=/usr/bin/Xvfb :99 -screen 0 1440x900x24 -ac +extension GLX +render -noreset
        Restart=always
        RestartSec=3

        [Install]
        WantedBy=multi-user.target
        UNIT);

        $sshService->exec('systemctl daemon-reload && systemctl enable --now xvfb');

        // Caddy reverse proxy — terminates TLS via Let's Encrypt (sslip.io domain).
        // Per-agent routes are added as snippets in /etc/caddy/conf.d/ by the agent install script.
        $hostname = $this->sslipHostname();
        $sshService->exec('mkdir -p /etc/caddy/conf.d');
        $sshService->writeFile('/etc/caddy/Caddyfile', <<<CADDY
        {$hostname} {
            import /etc/caddy/conf.d/*.caddy
        }
        CADDY);
        $sshService->exec('systemctl restart caddy');

        Log::info("VNC browser sharing configured on server {$this->server->id} at {$hostname}");
    }

    private function sslipHostname(): string
    {
        $dashedIp = str_replace('.', '-', $this->server->ipv4_address);

        return "{$dashedIp}.sslip.io";
    }

    private function installCredentialWrappers(SshService $sshService): void
    {
        // OpenClaw's exec tool resolves binaries directly (not via PATH), so wrappers
        // at /usr/local/bin/ are bypassed. We replace the actual binaries at /usr/bin/
        // with wrappers that auto-detect agent credentials from CWD, then delegate to
        // the real binaries saved as .real suffixed copies.
        $sshService->exec('test -f /usr/bin/gh && ! -f /usr/bin/gh.real && cp /usr/bin/gh /usr/bin/gh.real || true');
        $sshService->exec('test -f /usr/bin/git && ! -f /usr/bin/git.real && cp /usr/bin/git /usr/bin/git.real || true');

        $sshService->writeFile('/usr/bin/gh', <<<'WRAPPER'
        #!/bin/bash
        AGENT_DIR=$(echo "$PWD" | sed -n 's|\(/root/\.openclaw/agents/[^/]*\).*|\1|p')
        if [ -z "$AGENT_DIR" ]; then
            AGENT_DIR=$(pwd -P | sed -n 's|\(/mnt/openclaw-data/agents/[^/]*\).*|\1|p')
        fi
        if [ -n "$AGENT_DIR" ] && [ -d "$AGENT_DIR/.gh" ]; then
            export GH_CONFIG_DIR="$AGENT_DIR/.gh"
            export GIT_CONFIG_GLOBAL="$AGENT_DIR/.gitconfig"
        fi
        exec /usr/bin/gh.real "$@"
        WRAPPER);

        $sshService->writeFile('/usr/bin/git', <<<'WRAPPER'
        #!/bin/bash
        AGENT_DIR=$(echo "$PWD" | sed -n 's|\(/root/\.openclaw/agents/[^/]*\).*|\1|p')
        if [ -z "$AGENT_DIR" ]; then
            AGENT_DIR=$(pwd -P | sed -n 's|\(/mnt/openclaw-data/agents/[^/]*\).*|\1|p')
        fi
        if [ -n "$AGENT_DIR" ] && [ -f "$AGENT_DIR/.gitconfig" ]; then
            export GIT_CONFIG_GLOBAL="$AGENT_DIR/.gitconfig"
        fi
        exec /usr/bin/git.real "$@"
        WRAPPER);

        $sshService->exec('chmod +x /usr/bin/gh /usr/bin/git /usr/bin/gh.real /usr/bin/git.real');
    }

    private function approveDevicePairing(SshService $sshService): void
    {
        try {
            $sshService->exec('openclaw devices approve --latest');
        } catch (\RuntimeException) {
            Log::info("Device pairing approve returned non-zero on server {$this->server->id} (may already be paired)");
        }
    }

    private function waitForGateway(SshService $sshService): void
    {
        $delays = [5, 10, 10, 15];

        foreach ($delays as $delay) {
            try {
                $sshService->exec('openclaw gateway call health --timeout 5000 2>&1');

                return;
            } catch (\RuntimeException) {
                Log::info("Gateway not ready on server {$this->server->id}, retrying in {$delay}s...");
                sleep($delay);
            }
        }

        Log::warning("Gateway did not become ready on server {$this->server->id} after retries");
    }
}
