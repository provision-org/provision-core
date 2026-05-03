<?php

namespace App\Services\Scripts;

use App\Enums\HarnessType;
use App\Models\Server;
use App\Services\OpenClawDefaultsService;
use App\Support\OpenClawConfig;
use Illuminate\Support\Str;

class ServerSetupScriptService
{
    public function __construct(
        private OpenClawDefaultsService $defaultsService,
    ) {}

    /**
     * Create an HMAC-signed URL for the server setup script (10-min expiry).
     */
    public function buildSignedUrl(Server $server): string
    {
        $expiresAt = now()->addMinutes(10)->timestamp;
        $signature = hash_hmac('sha256', "server-setup|{$server->id}|{$expiresAt}", config('app.key'));

        return url("/api/servers/{$server->id}/setup-script?expires_at={$expiresAt}&signature={$signature}");
    }

    /**
     * Create an HMAC-signed callback URL for server setup progress/ready/error webhooks.
     */
    public function buildCallbackUrl(Server $server): string
    {
        $expiresAt = now()->addMinutes(30)->timestamp;
        $signature = hash_hmac('sha256', "server-setup-callback|{$server->id}|{$expiresAt}", config('app.key'));

        return url("/api/webhooks/server-setup?server_id={$server->id}&expires_at={$expiresAt}&signature={$signature}");
    }

    /**
     * Generate the full bash setup script that replaces SetupOpenClawOnServerJob SSH operations.
     */
    public function generateScript(Server $server): string
    {
        $server->loadMissing(['team.apiKeys']);

        $callbackUrl = $this->buildCallbackUrl($server);
        $vncPassword = $server->vnc_password ?: Str::random(16);
        $server->forceFill(['vnc_password' => $vncPassword])->saveQuietly();
        $hostname = $this->sslipHostname($server);
        $timezone = $server->team->timezone ?? 'UTC';
        $harnessType = $server->team->harness_type ?? HarnessType::Hermes;
        $isOpenClaw = $harnessType === HarnessType::OpenClaw;

        // Only build OpenClaw config for OpenClaw teams
        $onboardFlags = $isOpenClaw ? implode(' ', config('openclaw.onboard_flags')) : '';
        $openclawConfig = $isOpenClaw ? $this->buildOpenClawConfig($server) : [];
        $openclawConfigJson = $isOpenClaw ? OpenClawConfig::toJson($openclawConfig) : '{}';

        $lines = [
            '#!/bin/bash',
            'set -e',
            '',
            '# --- Server Setup Script ---',
            "# Server: {$server->id}",
            '# Generated: '.now()->toIso8601String(),
            '',
        ];

        // Progress callback function
        $lines[] = '# --- Logging & Callbacks ---';
        $lines[] = 'SETUP_LOG=/var/log/provision-setup.log';
        $lines[] = 'exec > >(tee -a "$SETUP_LOG") 2>&1';
        $lines[] = '';
        $lines[] = 'ping_progress() {';
        $lines[] = '  echo "[setup] step: $1"';
        $lines[] = "  curl -sS -X POST '{$callbackUrl}' -d \"status=progress&step=\$1\" || true";
        $lines[] = '}';
        $lines[] = '';
        $lines[] = 'report_error() {';
        $lines[] = '  local line=$1';
        $lines[] = '  local cmd=$(sed -n "${line}p" "$0" 2>/dev/null || echo "unknown")';
        $lines[] = '  local msg="Setup failed at line ${line}: ${cmd}"';
        $lines[] = '  echo "[setup] ERROR: $msg"';
        $lines[] = '  # Send last 20 lines of log as context';
        $lines[] = '  local context=$(tail -20 "$SETUP_LOG" 2>/dev/null | head -c 500 | python3 -c "import sys,urllib.parse;print(urllib.parse.quote(sys.stdin.read()))" 2>/dev/null || echo "no+context")';
        $lines[] = "  curl -sS -X POST '{$callbackUrl}' -d \"status=error&error_message=\${msg}&context=\${context}\" || true";
        $lines[] = '  exit 1';
        $lines[] = '}';
        $lines[] = '';
        $lines[] = 'trap \'report_error $LINENO\' ERR';
        $lines[] = '';

        if ($isOpenClaw) {
            // 1. Onboard + Doctor (OpenClaw only)
            // Run onboard and doctor FIRST so they can create/fix their default config.
            // We overwrite with our config AFTER so our settings take precedence.
            $lines[] = '# --- Step 1: OpenClaw Onboard & Doctor ---';
            $lines[] = 'ping_progress "onboarding"';
            $lines[] = "openclaw onboard {$onboardFlags}";
            $lines[] = '';

            // Capture version
            $lines[] = '# Capture OpenClaw version';
            $lines[] = 'OPENCLAW_VERSION=$(openclaw --version 2>/dev/null | sed "s/openclaw //" || echo "unknown")';
            $lines[] = '';

            // Run doctor in non-interactive mode for settings migrations only.
            // NEVER pipe `yes |` — that answers yes to doctor's "install missing
            // plugin deps?" prompt, which shells out to `npm install` inside
            // /usr/lib/node_modules/openclaw/node_modules/ and races the existing
            // tree (ENOTEMPTY rename, ENOENT tar extraction), leaving the install
            // worse than it started.
            $lines[] = '# Non-interactive settings migration (no --fix, no plugin reinstall)';
            $lines[] = 'openclaw doctor --non-interactive 2>&1 || true';
            $lines[] = '';

            // 2. Write openclaw.json AFTER onboard+doctor (our config overwrites their defaults)
            $lines[] = '# --- Step 2: Write Provision Config (overwrites onboard/doctor defaults) ---';
            $lines[] = 'ping_progress "configuring_defaults"';
            $lines[] = 'mkdir -p /root/.openclaw';
            $lines[] = $this->buildHeredoc('/root/.openclaw/openclaw.json', $openclawConfigJson);
            $lines[] = '';

            // 3. Install ByteRover (non-fatal)
            $lines[] = '# --- Step 3: Install ByteRover (non-fatal) ---';
            $lines[] = 'ping_progress "installing_advanced_memory"';
            $lines[] = 'export XDG_RUNTIME_DIR=/run/user/$(id -u)';
            $lines[] = 'openclaw plugins install @byterover/byterover 2>&1 || true';
            $lines[] = '';
        } else {
            // Hermes: just ensure the data directories exist
            $lines[] = '# --- Hermes: Setup Data Directories ---';
            $lines[] = 'ping_progress "configuring_defaults"';
            $lines[] = 'mkdir -p /root/.openclaw /mnt/openclaw-data/agents /mnt/openclaw-data/logs';
            $lines[] = '';
        }

        // 4. VNC display setup
        $lines[] = '# --- Step 4: VNC Display Setup ---';
        $lines[] = 'ping_progress "setting_up_vnc"';
        $lines[] = 'mkdir -p /root/.vnc';
        $lines[] = '# Wait for x11vnc to be available (cloud-init may still be installing packages)';
        $lines[] = 'for i in 1 2 3 4 5; do command -v x11vnc &>/dev/null && break; sleep 10; done';
        $lines[] = "x11vnc -storepasswd '{$vncPassword}' /root/.vnc/passwd || true";
        $lines[] = '';

        // Xvfb systemd unit
        $lines[] = '# Xvfb virtual framebuffer service';
        $lines[] = $this->buildHeredoc('/etc/systemd/system/xvfb.service', <<<'UNIT'
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
        $lines[] = 'systemctl daemon-reload && systemctl enable --now xvfb';
        $lines[] = '';

        // 5. Caddyfile
        $lines[] = '# --- Step 5: Caddy Reverse Proxy ---';
        $lines[] = 'mkdir -p /etc/caddy/conf.d';
        $lines[] = $this->buildHeredoc('/etc/caddy/Caddyfile', <<<CADDY
{$hostname} {
    import /etc/caddy/conf.d/*.caddy
}
CADDY);
        $lines[] = 'systemctl restart caddy';
        $lines[] = '';

        // 6. Credential wrappers
        $lines[] = '# --- Step 6: Credential Wrappers ---';
        $lines[] = 'ping_progress "installing_credential_wrappers"';
        $lines[] = '[ -f /usr/bin/gh ] && [ ! -f /usr/bin/gh.real ] && cp /usr/bin/gh /usr/bin/gh.real || true';
        $lines[] = '[ -f /usr/bin/git ] && [ ! -f /usr/bin/git.real ] && cp /usr/bin/git /usr/bin/git.real || true';
        $lines[] = '';

        $lines[] = $this->buildHeredoc('/usr/bin/gh', <<<'WRAPPER'
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

        $lines[] = $this->buildHeredoc('/usr/bin/git', <<<'WRAPPER'
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

        $lines[] = 'chmod +x /usr/bin/gh /usr/bin/git /usr/bin/gh.real /usr/bin/git.real';
        $lines[] = '';

        if ($isOpenClaw) {
            // 7. Install gateway (doctor already ran in step 1)
            $lines[] = '# --- Step 7: Install Gateway ---';
            $lines[] = 'ping_progress "installing_gateway"';
            $lines[] = 'export XDG_RUNTIME_DIR=/run/user/$(id -u)';
            $lines[] = 'loginctl enable-linger root';
            $lines[] = '';
            $lines[] = '# Startup optimizations (recommended by openclaw doctor)';
            $lines[] = 'export NODE_COMPILE_CACHE=/var/tmp/openclaw-compile-cache';
            $lines[] = 'mkdir -p /var/tmp/openclaw-compile-cache';
            $lines[] = 'export OPENCLAW_NO_RESPAWN=1';
            $lines[] = '';
            $lines[] = 'openclaw gateway install --force';
            $lines[] = '';

            // Re-write our config — gateway install may have modified it
            $lines[] = '# Re-apply Provision config (gateway install may modify openclaw.json)';
            $lines[] = $this->buildHeredoc('/root/.openclaw/openclaw.json', $openclawConfigJson);
            $lines[] = '';

            // Install dotenv for provision-tasks skill (Node.js dependency)
            $lines[] = '# Install dotenv for agent skills';
            $lines[] = 'npm install -g dotenv 2>/dev/null || true';
            $lines[] = '';

            // Workaround for OpenClaw bug #24016/#63856: the binary has hardcoded
            // /home/sprite/.openclaw/ paths for auth resolution. Symlink so the
            // path resolves to /root/.openclaw/ on our servers.
            $lines[] = '# Symlink /home/sprite/.openclaw → /root/.openclaw (OpenClaw binary path workaround)';
            $lines[] = 'mkdir -p /home/sprite';
            $lines[] = 'ln -sfn /root/.openclaw /home/sprite/.openclaw';
            $lines[] = '';

            // Timezone + DISPLAY + startup optimizations for gateway service
            $lines[] = '# Gateway systemd overrides (timezone + display + perf)';
            $lines[] = 'mkdir -p /root/.config/systemd/user/openclaw-gateway.service.d';
            $lines[] = $this->buildHeredoc(
                '/root/.config/systemd/user/openclaw-gateway.service.d/overrides.conf',
                <<<OVERRIDE
[Service]
Environment=OPENCLAW_TZ={$timezone}
Environment=DISPLAY=:99
Environment=NODE_COMPILE_CACHE=/var/tmp/openclaw-compile-cache
Environment=OPENCLAW_NO_RESPAWN=1
OVERRIDE
            );
            $lines[] = 'systemctl --user daemon-reload';
            $lines[] = 'systemctl --user restart openclaw-gateway';
            $lines[] = '';

            // 8. Wait for gateway + grant operator.read scope
            $lines[] = '# --- Step 8: Wait for Gateway & Grant operator.read Scope ---';
            $lines[] = 'ping_progress "starting_services"';
            $lines[] = 'sleep 15';
            $lines[] = '';
            $lines[] = '# OpenClaw 2026.5.2+ split bootstrap into operator.pairing (auto) and';
            $lines[] = '# operator.read (must be explicitly approved). The CLI `devices approve`';
            $lines[] = '# itself requires operator.read to talk to the gateway — chicken-and-egg.';
            $lines[] = '# Patch paired.json directly to grant operator.read, drop any pending';
            $lines[] = '# scope-upgrade requests, and restart the gateway. Idempotent on older';
            $lines[] = '# versions where the scope was already auto-granted.';
            $lines[] = 'if [ -f /root/.openclaw/devices/paired.json ]; then';
            $lines[] = '  cp /root/.openclaw/devices/paired.json /root/.openclaw/devices/paired.json.bak.$(date +%s)';
            $lines[] = '  jq \'map_values(.scopes |= ((. // []) + ["operator.read"] | unique) | .approvedScopes |= ((. // []) + ["operator.read"] | unique) | (.tokens // {}) |= map_values(.scopes |= ((. // []) + ["operator.read"] | unique)))\' /root/.openclaw/devices/paired.json > /root/.openclaw/devices/paired.json.new && mv /root/.openclaw/devices/paired.json.new /root/.openclaw/devices/paired.json';
            $lines[] = '  echo "{}" > /root/.openclaw/devices/pending.json';
            $lines[] = '  systemctl --user restart openclaw-gateway';
            $lines[] = '  sleep 5';
            $lines[] = 'fi';
            $lines[] = '';

            // Gateway health check retry loop
            $lines[] = '# Wait for gateway health';
            $lines[] = 'GATEWAY_READY=0';
            $lines[] = 'for DELAY in 5 10 10 15; do';
            $lines[] = '  if openclaw gateway call health --timeout 5000 2>&1; then';
            $lines[] = '    GATEWAY_READY=1';
            $lines[] = '    break';
            $lines[] = '  fi';
            $lines[] = '  # Re-grant operator.read in case a new device pairing came in mid-startup';
            $lines[] = '  if [ -f /root/.openclaw/devices/paired.json ]; then';
            $lines[] = '    jq \'map_values(.scopes |= ((. // []) + ["operator.read"] | unique) | .approvedScopes |= ((. // []) + ["operator.read"] | unique) | (.tokens // {}) |= map_values(.scopes |= ((. // []) + ["operator.read"] | unique)))\' /root/.openclaw/devices/paired.json > /root/.openclaw/devices/paired.json.new 2>/dev/null && mv /root/.openclaw/devices/paired.json.new /root/.openclaw/devices/paired.json';
            $lines[] = '    echo "{}" > /root/.openclaw/devices/pending.json';
            $lines[] = '    systemctl --user restart openclaw-gateway 2>/dev/null || true';
            $lines[] = '  fi';
            $lines[] = '  sleep $DELAY';
            $lines[] = 'done';
            $lines[] = '';
        } else {
            // Hermes: just enable linger for user services (needed by Hermes gateway)
            $lines[] = '# --- Hermes: Enable User Services ---';
            $lines[] = 'ping_progress "installing_gateway"';
            $lines[] = 'export XDG_RUNTIME_DIR=/run/user/$(id -u)';
            $lines[] = 'loginctl enable-linger root';
            $lines[] = 'GATEWAY_READY=1';
            $lines[] = '';
        }

        // 9. Volume symlinks
        if ($server->provider_volume_id) {
            $lines[] = '# --- Step 9: Volume Symlinks ---';
            $lines[] = 'ping_progress "finalizing"';
            $lines[] = 'rm -rf /root/.openclaw/agents /root/.openclaw/logs';
            $lines[] = 'mkdir -p /mnt/openclaw-data/agents /mnt/openclaw-data/logs';
            $lines[] = 'ln -sfn /mnt/openclaw-data/agents /root/.openclaw/agents';
            $lines[] = 'ln -sfn /mnt/openclaw-data/logs /root/.openclaw/logs';
            $lines[] = '';
        }

        // Shared Workspace
        $lines[] = '# --- Shared Workspace ---';
        $lines[] = 'mkdir -p /mnt/provision-shared';
        $lines[] = 'chmod 777 /mnt/provision-shared';
        $lines[] = '';

        // 10. Install and start provisiond (workforce agent daemon)
        $provisiondVersion = config('provision.provisiond_version', '0.3.0');
        $provisiondUrl = "https://github.com/provision-org/provision-core/releases/download/provisiond-v{$provisiondVersion}/provisiond.mjs";
        $apiUrl = config('app.url');
        $daemonToken = $server->daemon_token;

        $lines[] = '# --- Step 10: Install provisiond ---';
        $lines[] = 'ping_progress "installing_daemon"';
        $lines[] = 'mkdir -p /opt/provisiond /etc/provisiond';
        $lines[] = 'PROVISIOND_RETRIES=0';
        $lines[] = 'while [ $PROVISIOND_RETRIES -lt 3 ]; do';
        $lines[] = "  curl -fsSL '{$provisiondUrl}' -o /opt/provisiond/provisiond.mjs && break";
        $lines[] = '  PROVISIOND_RETRIES=$((PROVISIOND_RETRIES + 1))';
        $lines[] = '  echo "[setup] provisiond download attempt $PROVISIOND_RETRIES failed, retrying in 5s..."';
        $lines[] = '  sleep 5';
        $lines[] = 'done';
        $lines[] = 'if [ ! -f /opt/provisiond/provisiond.mjs ]; then';
        $lines[] = '  echo "[setup] FATAL: provisiond download failed after 3 attempts"';
        $lines[] = '  exit 1';
        $lines[] = 'fi';
        $lines[] = "echo '{\"version\":\"{$provisiondVersion}\"}' > /opt/provisiond/package.json";
        $lines[] = '';

        // Write provisiond config
        if ($daemonToken) {
            $provisiondConfig = json_encode([
                'api_url' => $apiUrl,
                'api_token' => $daemonToken,
                'server_id' => $server->id,
                'poll_interval_seconds' => 30,
                'max_concurrent_tasks' => 2,
                'task_timeout_seconds' => 600,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            $lines[] = "cat > /etc/provisiond/config.json << 'PROVISIOND_CONFIG'";
            $lines[] = $provisiondConfig;
            $lines[] = 'PROVISIOND_CONFIG';
            $lines[] = '';

            // Create systemd service
            $lines[] = 'cat > /etc/systemd/system/provisiond.service << \'PROVISIOND_SERVICE\'';
            $lines[] = '[Unit]';
            $lines[] = 'Description=Provision Agent Daemon';
            $lines[] = 'After=network.target';
            $lines[] = '';
            $lines[] = '[Service]';
            $lines[] = 'Type=simple';
            $lines[] = 'ExecStart=/usr/bin/node /opt/provisiond/provisiond.mjs';
            $lines[] = 'Restart=always';
            $lines[] = 'RestartSec=10';
            $lines[] = 'Environment=NODE_ENV=production';
            $lines[] = '';
            $lines[] = '[Install]';
            $lines[] = 'WantedBy=multi-user.target';
            $lines[] = 'PROVISIOND_SERVICE';
            $lines[] = '';
            $lines[] = 'systemctl daemon-reload';
            $lines[] = 'systemctl enable provisiond';
            $lines[] = 'systemctl start provisiond';
            $lines[] = 'echo "[setup] provisiond started successfully"';
            $lines[] = '';
        }

        // 11. Final ready callback
        $lines[] = '# --- Step 11: Signal Completion ---';
        $lines[] = 'if [ "$GATEWAY_READY" -eq 1 ]; then';
        $lines[] = "  curl -sS -X POST '{$callbackUrl}' -d 'status=ready' || true";
        $lines[] = 'else';
        $lines[] = "  curl -sS -X POST '{$callbackUrl}' -d 'status=error&error_message=Gateway+did+not+become+healthy' || true";
        $lines[] = 'fi';

        return implode("\n", $lines)."\n";
    }

    /**
     * Build the full openclaw.json config array, pre-computed in PHP.
     *
     * This replaces the read-modify-write cycle in configureOpenClawDefaults().
     *
     * @return array<string, mixed>
     */
    private function buildOpenClawConfig(Server $server): array
    {
        $defaults = $this->defaultsService->buildDefaults($server);

        $config = [];

        // Bindings — empty, agents add their own via install scripts
        $config['bindings'] = [];

        // Agents — sandbox off, typing indicator, heartbeat light context
        $config['agents'] = [
            'defaults' => array_replace_recursive([
                'sandbox' => ['mode' => 'off'],
                'typingMode' => 'thinking',
                'heartbeat' => ['lightContext' => true],
            ], $defaults),
        ];

        // Browser — managed Chrome, no sandbox (running as root)
        $config['browser'] = [
            'enabled' => true,
            'headless' => false,
            'noSandbox' => true,
            'executablePath' => config('openclaw.browser_executable_path'),
            'snapshotDefaults' => ['mode' => 'efficient'],
            'extraArgs' => ['--ozone-override-screen-size=1440,900', '--window-size=1440,900'],
        ];

        // Channels — empty, agent install scripts add per-agent accounts
        $config['channels'] = [];

        // Gateway — local mode with auth token, loopback binding + HTTP API
        $gatewayToken = $server->gateway_token ?: bin2hex(random_bytes(16));
        $server->forceFill(['gateway_token' => $gatewayToken])->saveQuietly();

        $config['gateway'] = [
            'mode' => 'local',
            'bind' => config('openclaw.gateway_bind'),
            'auth' => [
                'token' => $gatewayToken,
            ],
            'http' => [
                'endpoints' => [
                    'chatCompletions' => ['enabled' => true],
                    'responses' => ['enabled' => true],
                ],
            ],
        ];

        // Logging — redact sensitive data from tool outputs
        $config['logging'] = [
            'redactSensitive' => 'tools',
        ];

        // Messages — debounce rapid messages into single turns
        $config['messages'] = [
            'queue' => [
                'mode' => 'collect',
                'debounceMs' => 2000,
            ],
            'inbound' => [
                'debounceMs' => 2000,
            ],
        ];

        // Plugins — disable device-pair to prevent pairing deadlock on headless servers
        $config['plugins'] = ['entries' => [
            'device-pair' => ['enabled' => false],
        ]];

        // Session management — multi-user isolation and cleanup
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

        // Skills — watch for new/updated SKILL.md files mid-session
        $config['skills'] = [
            'load' => ['watch' => true],
        ];

        // Tools — full access (no profile), deny dangerous channel/automation tools
        $config['tools'] = [
            'deny' => ['canvas', 'nodes', 'gateway', 'config', 'system', 'telegram', 'whatsapp', 'discord', 'irc', 'googlechat', 'slack', 'signal', 'imessage'],
            'loopDetection' => [
                'enabled' => true,
                'historySize' => 30,
                'warningThreshold' => 10,
                'criticalThreshold' => 20,
            ],
        ];

        // openclaw 2026.5.2+ requires a meta block; without it the gateway
        // rolls back to the previous "last-good" backup on next restart.
        $config['meta'] = [
            'lastTouchedVersion' => $server->openclaw_version ?? '2026.5.2',
            'lastTouchedAt' => now()->toIso8601ZuluString('millisecond'),
        ];

        return $config;
    }

    private function sslipHostname(Server $server): string
    {
        $dashedIp = str_replace('.', '-', $server->ipv4_address);

        return "{$dashedIp}.sslip.io";
    }

    /**
     * Build a heredoc block that writes content to a file path.
     */
    private function buildHeredoc(string $filePath, string $content): string
    {
        return "cat > {$filePath} << 'HEREDOC_EOF'\n{$content}\nHEREDOC_EOF";
    }
}
