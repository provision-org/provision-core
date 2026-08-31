<?php

namespace App\Jobs;

use App\Models\Agent;
use App\Services\SshService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Final post-pairing cleanup for ChatGPT/Codex device-code auth.
 *
 * Once `pollAuthStatus` confirms the OAuth profile and updates the agent row,
 * this job picks up the slow SSH work — auth-order pinning, dropping the
 * synthesized api_key profile, killing the device-code tmux session, and
 * restarting the gateway so the new profile is picked up on the next request.
 *
 * Splitting this off keeps the polling endpoint fast (sub-second) so the
 * frontend can flip the modal to its success state without racing slow SSH.
 */
class FinalizeChatGPTAuthJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Agent $agent,
        public string $profileId,
        public string $tmuxSession,
    ) {}

    public function handle(SshService $ssh): void
    {
        if (! $this->agent->server) {
            return;
        }

        $ssh->connect($this->agent->server);

        try {
            $ssh->exec(sprintf(
                'openclaw models --agent %s auth order set --provider openai %s 2>&1',
                escapeshellarg($this->agent->harness_agent_id),
                escapeshellarg($this->profileId),
            ));

            // Auth profiles live in the agent's SQLite store on 2026.7.x — the
            // legacy auth-profiles.json is never read, so no file cleanup is
            // needed here; the order pin above already outranks any stale
            // api_key profile.

            $ssh->exec("tmux kill-session -t {$this->tmuxSession} 2>/dev/null; true");

            $ssh->exec(
                'export XDG_RUNTIME_DIR=/run/user/$(id -u) && systemctl --user restart openclaw-gateway',
            );
        } catch (\Throwable $e) {
            Log::warning('FinalizeChatGPTAuthJob failed; agent already has OAuth profile, gateway will pick it up on next restart', [
                'agent_id' => $this->agent->id,
                'error' => $e->getMessage(),
            ]);
        } finally {
            $ssh->disconnect();
        }
    }
}
