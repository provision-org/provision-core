<?php

namespace App\Services;

use App\Jobs\FinalizeChatGPTAuthJob;
use App\Models\Agent;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ChatGPTAuthService
{
    private const TMUX_SESSION_PREFIX = 'chatgpt-auth-';

    private const DEVICE_CODE_TIMEOUT_SECONDS = 900;

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
            'openclaw models --agent %s auth login --provider openai --method device-code; sleep %d',
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
     * Check whether the device-code login has produced an OpenAI OAuth profile.
     *
     * OpenClaw 2026.7.x stores auth profiles in per-agent SQLite
     * (openclaw-agent.sqlite), never in auth-profiles.json — the runtime no
     * longer reads that file at all — so the only supported view is
     * `openclaw models auth list --json`. On success, persist metadata to the
     * agent row and finalize asynchronously.
     *
     * @return array{state: 'pending'|'active'|'expired', email?: string, plan_type?: string, expires_at?: string}
     */
    public function pollAuthStatus(Agent $agent): array
    {
        $session = self::TMUX_SESSION_PREFIX.$agent->harness_agent_id;

        $this->sshService->connect($agent->server);

        try {
            $listJson = $this->sshService->exec(sprintf(
                'openclaw models --agent %s auth list --provider openai --json 2>/dev/null || echo null',
                escapeshellarg($agent->harness_agent_id),
            ));

            $profile = $this->extractOauthProfile($listJson);

            if ($profile === null) {
                $sessionAlive = trim($this->sshService->exec("tmux has-session -t {$session} 2>/dev/null && echo yes || echo no"));

                return ['state' => $sessionAlive === 'yes' ? 'pending' : 'expired'];
            }

            $profileId = $profile['profile_id'];
            $value = $profile;

            // Idempotency: if we've already finalized this email, skip the heavy
            // post-pairing work and just return the active state. Polling the
            // endpoint a second time after navigation shouldn't re-dispatch the
            // gateway restart.
            $alreadyFinalized = $agent->chatgpt_email === ($value['email'] ?? null)
                && $agent->auth_provider === 'chatgpt';

            $agent->update([
                'auth_provider' => 'chatgpt',
                'chatgpt_email' => $value['email'] ?? null,
                'chatgpt_plan_type' => $value['chatgptPlanType'] ?? null,
                'chatgpt_account_id' => $value['accountId'] ?? null,
                'chatgpt_connected_at' => now(),
                'chatgpt_token_expires_at' => isset($value['expires'])
                    ? Carbon::createFromTimestampMs($value['expires'])
                    : null,
            ]);

            // Dispatch the slow post-pairing work (auth order pin, profile cleanup,
            // tmux kill, gateway restart) so the polling endpoint can return fast
            // and the modal can flip to its success state immediately. Without
            // this, each poll would block on ~10-30s of SSH and the UI would
            // appear stuck on the device-code screen.
            if (! $alreadyFinalized) {
                FinalizeChatGPTAuthJob::dispatch($agent, $profileId, $session);
            }

            return [
                'state' => 'active',
                'email' => $value['email'] ?? null,
                'plan_type' => $value['chatgptPlanType'] ?? null,
                'expires_at' => isset($value['expires'])
                    ? Carbon::createFromTimestampMs($value['expires'])->toIso8601String()
                    : null,
                'redirect_to' => route('agents.setup', $agent),
            ];
        } finally {
            $this->sshService->disconnect();
        }
    }

    /**
     * Disconnect: kill any in-progress tmux session, remove the OpenAI OAuth
     * profiles from the agent's SQLite auth store via the supported CLI, and
     * reset the agent row's chatgpt_* columns + auth_provider. `models auth
     * logout` refreshes a running gateway itself, so no restart is needed.
     */
    public function disconnect(Agent $agent): void
    {
        if ($agent->auth_provider !== 'chatgpt' && empty($agent->chatgpt_email)) {
            return;
        }

        $session = self::TMUX_SESSION_PREFIX.$agent->harness_agent_id;

        try {
            $this->sshService->connect($agent->server);

            $this->sshService->exec("tmux kill-session -t {$session} 2>/dev/null; true");

            $this->sshService->exec(sprintf(
                'openclaw models --agent %s auth logout --provider openai 2>&1 || true',
                escapeshellarg($agent->harness_agent_id),
            ));
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
     * Parse `openclaw models auth list --json` output and pull the first
     * OpenAI OAuth profile. Output shape varies slightly across releases
     * (top-level array vs {profiles: [...]}, "type" vs "kind"), so parse
     * defensively and normalize to the keys the caller persists.
     *
     * @return array{profile_id: string, email: ?string, chatgptPlanType: ?string, accountId: ?string, expires: ?int}|null
     */
    private function extractOauthProfile(string $listJson): ?array
    {
        $decoded = json_decode(trim($listJson), true);

        if (! is_array($decoded)) {
            return null;
        }

        $profiles = array_is_list($decoded) ? $decoded : ($decoded['profiles'] ?? []);

        if (! is_array($profiles)) {
            return null;
        }

        foreach ($profiles as $key => $profile) {
            if (! is_array($profile)) {
                continue;
            }

            $provider = $profile['provider'] ?? null;
            $type = $profile['type'] ?? $profile['kind'] ?? $profile['mode'] ?? null;

            if (! in_array($provider, ['openai', 'openai-codex'], true) || $type !== 'oauth') {
                continue;
            }

            $profileId = $profile['profileId'] ?? $profile['id'] ?? (is_string($key) ? $key : null);

            if ($profileId === null) {
                continue;
            }

            // Email commonly rides in the profile id ("openai:user@host") when
            // no explicit field is present.
            $email = $profile['email'] ?? null;
            if ($email === null && str_contains($profileId, '@')) {
                $email = substr($profileId, strpos($profileId, ':') + 1) ?: null;
            }

            return [
                'profile_id' => $profileId,
                'email' => $email,
                'chatgptPlanType' => $profile['chatgptPlanType'] ?? $profile['planType'] ?? null,
                'accountId' => $profile['accountId'] ?? null,
                'expires' => isset($profile['expires']) && is_numeric($profile['expires'])
                    ? (int) $profile['expires']
                    : null,
            ];
        }

        return null;
    }

    /**
     * `--method device-code` on the OpenAI provider landed in 2026.5.2 (as
     * `openai-codex`, merged into `openai` in 2026.8.1). If the box is on an
     * older release, upgrade in-place and restart the gateway before we kick
     * off the flow. Caller already holds an SSH connection.
     */
    private function ensureOpenclawSupportsDeviceCode(): void
    {
        $required = config('provision.openclaw_version');

        // `openclaw --version` prints the bare version ("2026.7.1"); older
        // builds printed an "OpenClaw x.y.z" banner. Grab the first
        // version-looking token so both forms parse.
        $version = trim($this->sshService->exec(
            "openclaw --version 2>/dev/null | grep -oE '[0-9]{4}\\.[0-9]+\\.[0-9]+' | head -1",
        ));

        if ($version !== '' && version_compare($version, $required, '>=')) {
            return;
        }

        Log::info("Upgrading openclaw on agent server (was '{$version}', need {$required})");

        // Official updater: stages + doctor-verifies the candidate and rolls
        // back on failure. Raw npm swap kept as fallback for pre-updater builds.
        $this->sshService->exec(
            'openclaw update --tag '.escapeshellarg($required).' --yes --json 2>&1'
            .' || npm install -g openclaw@'.escapeshellarg($required).' 2>&1',
            600,
        );

        // 2026.8.1 refuses to boot while a legacy sessions.json exists —
        // doctor migrates it into the per-agent SQLite store. Must run before
        // the gateway restart or the box comes back with no gateway at all.
        $this->sshService->exec('openclaw doctor --fix --non-interactive 2>&1 || true', 300);

        // No pairing re-seed needed: since 2026.7.1 (#95997) a loopback CLI
        // call with the gateway token bypasses device pairing entirely.
        $this->sshService->exec(
            'export XDG_RUNTIME_DIR=/run/user/$(id -u) && systemctl --user restart openclaw-gateway 2>&1 || true',
        );

        sleep(3);
    }
}
