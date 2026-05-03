<?php

namespace App\Services;

use App\Models\Agent;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ChatGPTAuthService
{
    private const TMUX_SESSION_PREFIX = 'chatgpt-auth-';

    private const DEVICE_CODE_TIMEOUT_SECONDS = 900;

    private const REQUIRED_OPENCLAW_VERSION = '2026.5.2';

    public function __construct(private SshService $sshService) {}

    /**
     * Kick off an openclaw device-code OAuth flow inside a detached tmux session
     * on the agent server. Returns the verification URL + user code so the UI
     * can render them and start polling.
     *
     * @return array{verification_url: string, user_code: string, expires_at: int, session: string}
     */
    public function startDeviceCodeFlow(Agent $agent): array
    {
        if (! $agent->harness_agent_id) {
            throw new RuntimeException('Agent has no harness_agent_id; provisioning may be incomplete.');
        }

        $session = self::TMUX_SESSION_PREFIX.$agent->harness_agent_id;
        $command = sprintf(
            'openclaw models --agent %s auth login --provider openai-codex --method device-code; sleep %d',
            escapeshellarg($agent->harness_agent_id),
            self::DEVICE_CODE_TIMEOUT_SECONDS,
        );

        $this->sshService->connect($agent->server);

        try {
            $this->ensureOpenclawSupportsDeviceCode();

            $this->sshService->exec("tmux kill-session -t {$session} 2>/dev/null; true");
            $this->sshService->exec(sprintf(
                'tmux new-session -d -s %s -x 200 -y 50 %s',
                escapeshellarg($session),
                escapeshellarg($command),
            ));

            $deadline = microtime(true) + 20;
            $verificationUrl = null;
            $userCode = null;

            while (microtime(true) < $deadline) {
                $pane = $this->sshService->exec("tmux capture-pane -t {$session} -p -J -S -500 2>/dev/null || true");

                if (preg_match('#https://auth\.openai\.com/codex/device#', $pane)) {
                    $verificationUrl = 'https://auth.openai.com/codex/device';
                }

                if (preg_match('/Code:\s*([A-Z0-9\-]+)/', $pane, $matches)) {
                    $userCode = $matches[1];
                }

                if ($verificationUrl !== null && $userCode !== null) {
                    break;
                }

                usleep(1_500_000);
            }

            if ($verificationUrl === null || $userCode === null) {
                $this->sshService->exec("tmux kill-session -t {$session} 2>/dev/null; true");
                throw new RuntimeException('Failed to capture device code from openclaw within 20s');
            }

            return [
                'verification_url' => $verificationUrl,
                'user_code' => $userCode,
                'expires_at' => time() + self::DEVICE_CODE_TIMEOUT_SECONDS,
                'session' => $session,
            ];
        } finally {
            $this->sshService->disconnect();
        }
    }

    /**
     * Read auth-profiles.json on the agent server and check if an openai-codex
     * OAuth profile has been written. On success, persist metadata to the agent
     * row, pin the OAuth profile in auth-state.json, and restart the gateway.
     *
     * @return array{state: 'pending'|'active'|'expired', email?: string, plan_type?: string, expires_at?: string}
     */
    public function pollAuthStatus(Agent $agent): array
    {
        $session = self::TMUX_SESSION_PREFIX.$agent->harness_agent_id;
        $agentDir = "/root/.openclaw/agents/{$agent->harness_agent_id}/agent";

        $this->sshService->connect($agent->server);

        try {
            $profileJson = $this->sshService->exec(
                "jq '.profiles | to_entries | map(select(.value.provider == \"openai-codex\" and .value.type == \"oauth\")) | first' {$agentDir}/auth-profiles.json 2>/dev/null || echo null",
            );

            $profile = json_decode(trim($profileJson), true);

            if (! is_array($profile) || ($profile['value']['type'] ?? null) !== 'oauth') {
                $sessionAlive = trim($this->sshService->exec("tmux has-session -t {$session} 2>/dev/null && echo yes || echo no"));

                return ['state' => $sessionAlive === 'yes' ? 'pending' : 'expired'];
            }

            $profileId = $profile['key'];
            $value = $profile['value'];

            $agent->update([
                'auth_provider' => 'chatgpt',
                'chatgpt_email' => $value['email'] ?? null,
                'chatgpt_plan_type' => $value['chatgptPlanType'] ?? null,
                'chatgpt_account_id' => $value['accountId'] ?? null,
                'chatgpt_connected_at' => now(),
                'chatgpt_token_expires_at' => isset($value['expires'])
                    ? \Carbon\Carbon::createFromTimestampMs($value['expires'])
                    : null,
            ]);

            $this->sshService->exec(sprintf(
                'openclaw models --agent %s auth order set --provider openai-codex %s 2>&1',
                escapeshellarg($agent->harness_agent_id),
                escapeshellarg($profileId),
            ));

            // Remove the synthesized openai-codex:default api_key so it can't out-rank
            // the OAuth profile on subsequent runs (the env var OPENAI_API_KEY also
            // synthesizes one at runtime, but auth-state.json's order has the OAuth
            // profile pinned first, which wins).
            $this->sshService->exec(sprintf(
                "jq 'del(.profiles.\"openai-codex:default\")' %s/auth-profiles.json > %s/auth-profiles.json.tmp && mv %s/auth-profiles.json.tmp %s/auth-profiles.json 2>/dev/null || true",
                $agentDir,
                $agentDir,
                $agentDir,
                $agentDir,
            ));

            $this->sshService->exec("tmux kill-session -t {$session} 2>/dev/null; true");

            $this->sshService->exec(
                'export XDG_RUNTIME_DIR=/run/user/$(id -u) && systemctl --user restart openclaw-gateway',
            );

            return [
                'state' => 'active',
                'email' => $value['email'] ?? null,
                'plan_type' => $value['chatgptPlanType'] ?? null,
                'expires_at' => isset($value['expires'])
                    ? \Carbon\Carbon::createFromTimestampMs($value['expires'])->toIso8601String()
                    : null,
            ];
        } finally {
            $this->sshService->disconnect();
        }
    }

    /**
     * Disconnect: kill any in-progress tmux session, remove the OAuth profile
     * and openai-codex order entry from auth-profiles/auth-state, restart the
     * gateway, and reset the agent row's chatgpt_* columns + auth_provider.
     */
    public function disconnect(Agent $agent): void
    {
        if ($agent->auth_provider !== 'chatgpt' && empty($agent->chatgpt_email)) {
            return;
        }

        $session = self::TMUX_SESSION_PREFIX.$agent->harness_agent_id;
        $agentDir = "/root/.openclaw/agents/{$agent->harness_agent_id}/agent";
        $email = $agent->chatgpt_email;

        try {
            $this->sshService->connect($agent->server);

            $this->sshService->exec("tmux kill-session -t {$session} 2>/dev/null; true");

            if ($email) {
                $profileKey = "openai-codex:{$email}";
                $this->sshService->exec(sprintf(
                    "jq 'del(.profiles[%s])' %s/auth-profiles.json > %s/auth-profiles.json.tmp && mv %s/auth-profiles.json.tmp %s/auth-profiles.json 2>/dev/null || true",
                    escapeshellarg('"'.$profileKey.'"'),
                    $agentDir,
                    $agentDir,
                    $agentDir,
                    $agentDir,
                ));
            }

            $this->sshService->exec(sprintf(
                "jq 'del(.order.\"openai-codex\") | del(.lastGood.\"openai-codex\")' %s/auth-state.json > %s/auth-state.json.tmp && mv %s/auth-state.json.tmp %s/auth-state.json 2>/dev/null || true",
                $agentDir,
                $agentDir,
                $agentDir,
                $agentDir,
            ));

            $this->sshService->exec(
                'export XDG_RUNTIME_DIR=/run/user/$(id -u) && systemctl --user restart openclaw-gateway',
            );
        } catch (\Throwable $e) {
            Log::warning("ChatGPT disconnect failed for agent {$agent->harness_agent_id}: {$e->getMessage()}");
        } finally {
            $this->sshService->disconnect();
        }

        $agent->update([
            'auth_provider' => 'openrouter',
            'chatgpt_email' => null,
            'chatgpt_plan_type' => null,
            'chatgpt_account_id' => null,
            'chatgpt_connected_at' => null,
            'chatgpt_token_expires_at' => null,
        ]);
    }

    /**
     * `--method device-code` for openai-codex landed in 2026.5.2. If the box
     * is on an older release, upgrade in-place and restart the gateway before
     * we kick off the flow. Caller already holds an SSH connection.
     */
    private function ensureOpenclawSupportsDeviceCode(): void
    {
        $version = trim($this->sshService->exec(
            "openclaw --version 2>/dev/null | sed -nE 's/.*OpenClaw ([0-9.]+).*/\\1/p' | head -1",
        ));

        if ($version !== '' && version_compare($version, self::REQUIRED_OPENCLAW_VERSION, '>=')) {
            return;
        }

        Log::info("Upgrading openclaw on agent server (was '{$version}', need ".self::REQUIRED_OPENCLAW_VERSION.')');

        $this->sshService->exec(
            'npm install -g openclaw@'.escapeshellarg(self::REQUIRED_OPENCLAW_VERSION).' 2>&1',
            300,
        );

        // Defensive: 2026.5.2 may regenerate a scope-upgrade pending request
        // on first startup that the CLI can't approve (chicken-and-egg). Patch
        // paired.json directly. Idempotent on boxes where scopes are already fine.
        $this->sshService->exec(
            'if [ -f /root/.openclaw/devices/paired.json ]; then'
            .' jq \'map_values(.scopes |= ((. // []) + ["operator.read"] | unique)'
            .' | .approvedScopes |= ((. // []) + ["operator.read"] | unique)'
            .' | (.tokens // {}) |= map_values(.scopes |= ((. // []) + ["operator.read"] | unique)))\''
            .' /root/.openclaw/devices/paired.json > /root/.openclaw/devices/paired.json.new'
            .' && mv /root/.openclaw/devices/paired.json.new /root/.openclaw/devices/paired.json;'
            .' echo "{}" > /root/.openclaw/devices/pending.json;'
            .' fi',
        );

        $this->sshService->exec(
            'export XDG_RUNTIME_DIR=/run/user/$(id -u) && systemctl --user restart openclaw-gateway 2>&1 || true',
        );

        sleep(3);
    }
}
