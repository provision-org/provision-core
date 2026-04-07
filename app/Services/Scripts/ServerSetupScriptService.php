<?php

namespace App\Services\Scripts;

use App\Enums\HarnessType;
use App\Models\Server;
use App\Services\OpenClawDefaultsService;
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
        $vncPassword = Str::random(16);
        $hostname = $this->sslipHostname($server);
        $timezone = $server->team->timezone ?? 'UTC';
        $harnessType = $server->team->harness_type ?? HarnessType::Hermes;
        $isOpenClaw = $harnessType === HarnessType::OpenClaw;

        // Only build OpenClaw config for OpenClaw teams
        $onboardFlags = $isOpenClaw ? implode(' ', config('openclaw.onboard_flags')) : '';
        $openclawConfig = $isOpenClaw ? $this->buildOpenClawConfig($server) : [];
        $openclawConfigJson = $isOpenClaw ? json_encode($openclawConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : '{}';

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
        $lines[] = "  echo \"[setup] step: \$1\"";
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
            // 1. Onboard (OpenClaw only)
            $lines[] = '# --- Step 1: OpenClaw Onboard ---';
            $lines[] = 'ping_progress "onboarding"';
            $lines[] = "openclaw onboard {$onboardFlags}";
            $lines[] = '';

            // Capture version
            $lines[] = '# Capture OpenClaw version';
            $lines[] = 'OPENCLAW_VERSION=$(openclaw --version 2>/dev/null | sed "s/openclaw //" || echo "unknown")';
            $lines[] = '';

            // 2. Write openclaw.json (pre-computed, no read-modify-write)
            $lines[] = '# --- Step 2: Write OpenClaw Config ---';
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
            // 7. Run doctor to auto-fix config issues, then install gateway
            $lines[] = '# --- Step 7: Run Doctor & Install Gateway ---';
            $lines[] = 'ping_progress "installing_gateway"';
            $lines[] = 'export XDG_RUNTIME_DIR=/run/user/$(id -u)';
            $lines[] = 'loginctl enable-linger root';
            $lines[] = '';
            $lines[] = '# Startup optimizations (recommended by openclaw doctor)';
            $lines[] = 'export NODE_COMPILE_CACHE=/var/tmp/openclaw-compile-cache';
            $lines[] = 'mkdir -p /var/tmp/openclaw-compile-cache';
            $lines[] = 'export OPENCLAW_NO_RESPAWN=1';
            $lines[] = '';
            $lines[] = '# Auto-fix any config issues before starting gateway';
            $lines[] = 'yes | openclaw doctor 2>&1 || true';
            $lines[] = '';
            $lines[] = 'openclaw gateway install --force';
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

            // 8. Wait for gateway + approve device pairing
            $lines[] = '# --- Step 8: Wait for Gateway & Approve Device Pairing ---';
            $lines[] = 'ping_progress "starting_services"';
            $lines[] = 'sleep 15';
            $lines[] = 'openclaw devices approve --latest || true';
            $lines[] = '';

            // Gateway health check retry loop
            $lines[] = '# Wait for gateway health';
            $lines[] = 'GATEWAY_READY=0';
            $lines[] = 'for DELAY in 5 10 10 15; do';
            $lines[] = '  if openclaw gateway call health --timeout 5000 2>&1; then';
            $lines[] = '    GATEWAY_READY=1';
            $lines[] = '    break';
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

        // 10. Install provisiond (workforce agent daemon)
        $provisiondVersion = config('provision.provisiond_version', '0.1.0');
        $provisiondUrl = "https://github.com/provision-org/provision-core/releases/download/provisiond-v{$provisiondVersion}/provisiond.mjs";
        $lines[] = '# --- Step 10: Install provisiond ---';
        $lines[] = 'ping_progress "installing_daemon"';
        $lines[] = 'mkdir -p /opt/provisiond';
        $lines[] = "curl -fsSL '{$provisiondUrl}' -o /opt/provisiond/provisiond.mjs || echo 'provisiond download failed (non-fatal)'";
        $lines[] = "echo '{\"version\":\"{$provisiondVersion}\"}' > /opt/provisiond/package.json";
        $lines[] = '';

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
        $config['channels'] = (object) [];

        // Gateway — local mode with auth token, loopback binding + HTTP API
        $gatewayToken = bin2hex(random_bytes(16));
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

        // Plugins — empty entries, ByteRover added only if install succeeds
        $config['plugins'] = ['entries' => (object) []];

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
