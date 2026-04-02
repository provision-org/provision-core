<?php

namespace App\Services;

use App\Contracts\CommandExecutor;
use App\Models\Agent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AgentScheduleService
{
    public function __construct(private SshService $sshService) {}

    /**
     * List cron jobs for an agent.
     *
     * @return array<int, array<string, mixed>>
     */
    public function list(Agent $agent): array
    {
        $server = $agent->server;
        $cacheKey = "crons:server:{$server->id}";

        $allCrons = Cache::remember($cacheKey, 30, function () use ($server) {
            try {
                $this->sshService->connect($server);
                $output = $this->sshService->exec('openclaw cron list --json 2>&1');

                Log::info("openclaw cron list output on server {$server->id}", ['output' => $output]);

                $decoded = json_decode($output, true);

                if (! is_array($decoded)) {
                    return [];
                }

                // Handle nested response format (e.g. { "crons": [...] })
                if (! array_is_list($decoded)) {
                    $decoded = $decoded['crons'] ?? $decoded['data'] ?? $decoded['jobs'] ?? $decoded['items'] ?? array_values($decoded);
                }

                // Ensure every element is an array (not a scalar from array_values on a flat object)
                return array_values(array_filter($decoded, 'is_array'));
            } catch (\RuntimeException $e) {
                Log::warning("Failed to list crons on server {$server->id}: {$e->getMessage()}");

                return [];
            } finally {
                $this->sshService->disconnect();
            }
        });

        $agentId = $agent->harness_agent_id;

        return array_values(array_filter($allCrons, fn (array $cron) => ($cron['agentId'] ?? null) === $agentId));
    }

    /**
     * Create a cron job for an agent.
     *
     * @return array<string, mixed>
     */
    public function create(Agent $agent, string $name, string $every, string $message, ?string $model = null): array
    {
        $server = $agent->server;
        $agentId = $agent->harness_agent_id;
        $escapedName = escapeshellarg($name);
        $escapedMessage = escapeshellarg($message);
        $escapedEvery = escapeshellarg($every);
        $deliveryFlags = $this->resolveDeliveryFlags($agent);
        $modelFlag = $model ? ' --model '.escapeshellarg($model) : '';

        $this->sshService->connect($server);

        try {
            $command = "openclaw cron add --agent {$agentId} --name {$escapedName} --every {$escapedEvery}{$modelFlag} --message {$escapedMessage}{$deliveryFlags} --best-effort-deliver --json 2>&1";
            $output = $this->sshService->exec($command);

            Log::info("openclaw cron add output on server {$server->id}", ['command' => $command, 'output' => $output]);

            $this->clearCache($server->id);

            return json_decode($output, true) ?? ['raw' => $output];
        } finally {
            $this->sshService->disconnect();
        }
    }

    /**
     * Edit a cron job.
     *
     * @param  array<string, string>  $changes
     * @return array<string, mixed>
     */
    public function edit(Agent $agent, string $cronId, array $changes): array
    {
        $server = $agent->server;
        $flags = '';

        if (isset($changes['name'])) {
            $flags .= ' --name '.escapeshellarg($changes['name']);
        }

        if (isset($changes['every'])) {
            $flags .= ' --every '.escapeshellarg($changes['every']);
        }

        if (isset($changes['message'])) {
            $flags .= ' --message '.escapeshellarg($changes['message']);
        }

        $escapedCronId = escapeshellarg($cronId);

        $this->sshService->connect($server);

        try {
            $output = $this->sshService->exec("openclaw cron edit {$escapedCronId}{$flags} --json 2>/dev/null");

            $this->clearCache($server->id);

            return json_decode($output, true) ?? [];
        } finally {
            $this->sshService->disconnect();
        }
    }

    /**
     * Delete a cron job.
     *
     * @return array<string, mixed>
     */
    public function delete(Agent $agent, string $cronId): array
    {
        $server = $agent->server;
        $escapedCronId = escapeshellarg($cronId);

        $this->sshService->connect($server);

        try {
            $output = $this->sshService->exec("openclaw cron rm {$escapedCronId} --json 2>/dev/null");

            $this->clearCache($server->id);

            return json_decode($output, true) ?? [];
        } finally {
            $this->sshService->disconnect();
        }
    }

    /**
     * Enable or disable a cron job.
     */
    public function toggle(Agent $agent, string $cronId, bool $enable): void
    {
        $server = $agent->server;
        $action = $enable ? 'enable' : 'disable';
        $escapedCronId = escapeshellarg($cronId);

        $this->sshService->connect($server);

        try {
            $this->sshService->exec("openclaw cron {$action} {$escapedCronId} 2>/dev/null");

            $this->clearCache($server->id);
        } finally {
            $this->sshService->disconnect();
        }
    }

    /**
     * Run a cron job immediately.
     */
    public function run(Agent $agent, string $cronId): void
    {
        $server = $agent->server;
        $escapedCronId = escapeshellarg($cronId);

        $this->sshService->connect($server);

        try {
            $this->sshService->exec("openclaw cron run {$escapedCronId} 2>/dev/null");
        } finally {
            $this->sshService->disconnect();
        }
    }

    /**
     * Create default cron jobs for an agent (email check if connected).
     *
     * Uses a lightweight bash script + crontab instead of openclaw cron
     * to avoid spinning up an LLM session every 5 minutes. The script
     * curls MailboxKit, and only triggers the agent if new mail exists.
     */
    public function createDefaultCrons(Agent $agent, CommandExecutor $executor): void
    {
        $agent->loadMissing('emailConnection');

        if (! $agent->emailConnection?->mailboxkit_inbox_id) {
            return;
        }

        $agentId = $agent->harness_agent_id;
        $agentDir = "/root/.openclaw/agents/{$agentId}";

        try {
            // Deploy the email check script
            $script = self::buildEmailCheckScript($agentId, $agentDir);
            $script = str_replace(['__AGENT_DIR__', '__AGENT_ID__'], [$agentDir, $agentId], $script);
            $executor->writeFile("{$agentDir}/check-email.sh", $script);
            $executor->exec("chmod +x {$agentDir}/check-email.sh");

            // Install crontab entry (idempotent — removes old entry first)
            $marker = "# provision-email-check-{$agentId}";
            $cronLine = "*/5 * * * * {$agentDir}/check-email.sh >> {$agentDir}/email-check.log 2>&1 {$marker}";

            $executor->exec("(crontab -l 2>/dev/null | grep -v '{$marker}'; echo '{$cronLine}') | crontab -");
        } catch (\RuntimeException $e) {
            Log::warning("Failed to create email check cron for agent {$agent->id}: {$e->getMessage()}");
        }
    }

    /**
     * Build the bash script that checks for new emails without using an LLM.
     *
     * Curls MailboxKit API for recent messages, compares against a
     * last-checked timestamp, and only invokes the agent if new mail exists.
     */
    public static function buildEmailCheckScript(string $agentId, string $agentDir): string
    {
        return <<<'BASH'
#!/bin/bash
# Email check script — runs via crontab, zero LLM cost when no new mail.

set -euo pipefail

AGENT_DIR="__AGENT_DIR__"
AGENT_ID="__AGENT_ID__"
ENV_FILE="${AGENT_DIR}/.env"
LAST_CHECK_FILE="${AGENT_DIR}/.email-last-check"

# Load agent credentials
if [ ! -f "$ENV_FILE" ]; then
    echo "$(date -Iseconds) ERROR: .env not found at $ENV_FILE"
    exit 0
fi
source "$ENV_FILE"

if [ -z "${MAILBOXKIT_API_KEY:-}" ] || [ -z "${MAILBOXKIT_INBOX_ID:-}" ]; then
    echo "$(date -Iseconds) ERROR: MAILBOXKIT_API_KEY or MAILBOXKIT_INBOX_ID not set"
    exit 0
fi

# Get last check timestamp (ISO 8601), default to 10 min ago
if [ -f "$LAST_CHECK_FILE" ]; then
    LAST_CHECK=$(cat "$LAST_CHECK_FILE")
else
    LAST_CHECK=$(date -u -Iseconds -d '-10 minutes' 2>/dev/null || date -u -v-10M '+%Y-%m-%dT%H:%M:%S+00:00')
fi

# Fetch recent messages
RESPONSE=$(curl -sS --max-time 15 \
    -H "Authorization: Bearer $MAILBOXKIT_API_KEY" \
    -H "Accept: application/json" \
    "https://mailboxkit.com/api/v1/inboxes/$MAILBOXKIT_INBOX_ID/messages?per_page=5" 2>/dev/null) || {
    echo "$(date -Iseconds) WARN: MailboxKit API request failed"
    exit 0
}

# Count messages newer than last check
NEW_COUNT=$(echo "$RESPONSE" | jq --arg since "$LAST_CHECK" '
    [.data[]? | select(.created_at > $since)] | length
' 2>/dev/null) || NEW_COUNT=0

# Update last check timestamp
date -u '+%Y-%m-%dT%H:%M:%S+00:00' > "$LAST_CHECK_FILE"

if [ "$NEW_COUNT" -gt 0 ]; then
    echo "$(date -Iseconds) INFO: $NEW_COUNT new message(s) found, notifying agent"

    # Build a summary of new messages for the agent
    SUMMARY=$(echo "$RESPONSE" | jq -r --arg since "$LAST_CHECK" '
        [.data[]? | select(.created_at > $since)] |
        map("- From: \(.from_email) | Subject: \(.subject // "(no subject)")") |
        join("\n")
    ' 2>/dev/null)

    export XDG_RUNTIME_DIR="/run/user/$(id -u)"
    openclaw agent --agent "$AGENT_ID" --message "You have $NEW_COUNT new email(s). Check your inbox and respond appropriately. New messages:
$SUMMARY" --deliver 2>/dev/null || echo "$(date -Iseconds) WARN: Failed to notify agent"
else
    echo "$(date -Iseconds) OK: No new messages"
fi
BASH;
    }

    /**
     * Resolve --channel and --account flags from agent connections (Slack > Telegram > Discord).
     */
    private function resolveDeliveryFlags(Agent $agent): string
    {
        $agent->loadMissing(['slackConnection', 'telegramConnection', 'discordConnection']);
        $agentId = $agent->harness_agent_id;

        if ($agent->slackConnection?->status === 'connected') {
            return " --channel slack --account slack-{$agentId}";
        }

        if ($agent->telegramConnection?->status === 'connected') {
            return " --channel telegram --account telegram-{$agentId}";
        }

        if ($agent->discordConnection?->status === 'connected') {
            return " --channel discord --account discord-{$agentId}";
        }

        return '';
    }

    private function clearCache(string $serverId): void
    {
        Cache::forget("crons:server:{$serverId}");
    }
}
