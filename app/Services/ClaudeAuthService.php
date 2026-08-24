<?php

namespace App\Services;

use App\Models\Agent;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

/**
 * BYO Claude subscription auth for an agent.
 *
 * The user runs `claude setup-token` on their own machine (or in the Claude
 * console), pastes the resulting sk-ant-oat01-... token into Provision, and
 * we pipe it into the agent's SQLite auth store via
 * `openclaw models auth paste-token` — the supported non-interactive
 * injection path on OpenClaw 2026.7.x. The token then routes anthropic/*
 * models through the customer's own Claude subscription.
 *
 * Deliberate constraints, surfaced in the UI:
 *  - Tokens expire and can be revoked by Anthropic; expect reconnects.
 *  - Usage draws from the customer's own subscription limits.
 *  - Upstream guidance reserves this for a customer's own agents — shared
 *    or pooled production capacity must use an Anthropic API key instead.
 */
class ClaudeAuthService
{
    private const PROFILE_ID = 'anthropic:manual';

    public function __construct(private SshService $sshService) {}

    /**
     * Store the setup token in the agent's auth store and pin the anthropic
     * auth order so the subscription profile outranks any API-key profile.
     *
     * @return array{state: 'active', expires_at: ?string}
     */
    public function connect(Agent $agent, string $setupToken): array
    {
        if (! $agent->harness_agent_id) {
            throw new RuntimeException('Agent has no harness_agent_id; provisioning may be incomplete.');
        }

        $setupToken = trim($setupToken);

        if (! str_starts_with($setupToken, 'sk-ant-oat')) {
            throw new InvalidArgumentException(
                'That does not look like a Claude setup token (expected it to start with "sk-ant-oat"). Run `claude setup-token` and paste the full token.',
            );
        }

        $this->sshService->connect($agent->server);

        try {
            try {
                $output = $this->sshService->exec(sprintf(
                    'printf %%s\\\\n %s | openclaw models --agent %s auth paste-token --provider anthropic 2>&1',
                    escapeshellarg($setupToken),
                    escapeshellarg($agent->harness_agent_id),
                ));
            } catch (\Throwable) {
                // Never surface the exec exception: its message embeds the
                // full command line, token included, and flows into logs.
                throw new RuntimeException('OpenClaw rejected the setup token. Check that it was copied completely and has not been revoked.');
            }

            if (! $this->tokenProfileExists($agent)) {
                throw new RuntimeException('OpenClaw did not accept the setup token: '.trim($output));
            }

            $this->sshService->exec(sprintf(
                'openclaw models --agent %s auth order set --provider anthropic %s 2>&1 || true',
                escapeshellarg($agent->harness_agent_id),
                escapeshellarg(self::PROFILE_ID),
            ));
        } finally {
            $this->sshService->disconnect();
        }

        $agent->update([
            'auth_provider' => 'claude',
            'claude_connected_at' => now(),
            // Setup tokens are long-lived but revocable; OpenClaw tracks the
            // real expiry internally and rotates cooldowns on auth failures.
            'claude_token_expires_at' => null,
        ]);

        return ['state' => 'active', 'expires_at' => null];
    }

    /**
     * @return array{state: 'active'|'disconnected', connected_at?: ?string}
     */
    public function status(Agent $agent): array
    {
        $this->sshService->connect($agent->server);

        try {
            $active = $this->tokenProfileExists($agent);
        } finally {
            $this->sshService->disconnect();
        }

        if (! $active && $agent->auth_provider === 'claude') {
            return ['state' => 'disconnected'];
        }

        return $active
            ? ['state' => 'active', 'connected_at' => $agent->claude_connected_at?->toIso8601String()]
            : ['state' => 'disconnected'];
    }

    /**
     * Remove the anthropic auth profiles and fall back to managed routing.
     *
     * Also guards on claude_connected_at: flows that flip auth_provider away
     * from 'claude' first (model change, switch to pay-per-use) must still be
     * able to revoke the stored setup token afterwards — a customer
     * credential must never be stranded on the server.
     */
    public function disconnect(Agent $agent): void
    {
        if ($agent->auth_provider !== 'claude' && empty($agent->claude_connected_at)) {
            return;
        }

        try {
            $this->sshService->connect($agent->server);

            $this->sshService->exec(sprintf(
                'openclaw models --agent %s auth logout --provider anthropic 2>&1 || true',
                escapeshellarg($agent->harness_agent_id),
            ));
        } catch (\Throwable $e) {
            Log::warning("Claude disconnect failed for agent {$agent->harness_agent_id}: {$e->getMessage()}");
        } finally {
            $this->sshService->disconnect();
        }

        $agent->update([
            'auth_provider' => 'openrouter',
            'claude_connected_at' => null,
            'claude_token_expires_at' => null,
        ]);
    }

    /**
     * Whether the agent's auth store holds a non-api_key anthropic profile
     * (a setup token imports as a token/oauth-style profile).
     */
    private function tokenProfileExists(Agent $agent): bool
    {
        $listJson = $this->sshService->exec(sprintf(
            'openclaw models --agent %s auth list --provider anthropic --json 2>/dev/null || echo null',
            escapeshellarg($agent->harness_agent_id),
        ));

        $decoded = json_decode(trim($listJson), true);

        if (! is_array($decoded)) {
            return false;
        }

        $profiles = array_is_list($decoded) ? $decoded : ($decoded['profiles'] ?? []);

        foreach ((array) $profiles as $profile) {
            if (! is_array($profile)) {
                continue;
            }

            $type = $profile['type'] ?? $profile['kind'] ?? $profile['mode'] ?? null;

            if (($profile['provider'] ?? null) === 'anthropic' && in_array($type, ['token', 'oauth'], true)) {
                return true;
            }
        }

        return false;
    }
}
