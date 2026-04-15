<?php

namespace App\Jobs;

use App\Enums\AgentStatus;
use App\Models\Agent;
use App\Models\Task;
use App\Services\SshService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class NotifyDelegatorAboutTaskCompletionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 90;

    public function __construct(
        public Agent $agent,
        public Task $task,
        public string $newStatus,
    ) {}

    public function handle(SshService $sshService): void
    {
        if ($this->agent->status !== AgentStatus::Active || ! $this->agent->server) {
            Log::info("Skipping delegator notification — agent {$this->agent->id} not active or no server");

            return;
        }

        $this->agent->loadMissing(['telegramConnection', 'slackConnection', 'discordConnection']);
        $this->task->loadMissing('agent');

        $executorName = $this->task->agent?->name ?? 'Unknown agent';

        $message = match ($this->newStatus) {
            'done' => "@{$executorName} finished the task: \"{$this->task->title}\"",
            'failed' => "@{$executorName} failed the task: \"{$this->task->title}\"",
            'blocked' => "@{$executorName} is blocked on: \"{$this->task->title}\"",
            default => "Task status changed to {$this->newStatus}: \"{$this->task->title}\"",
        };

        if ($this->task->result_summary && $this->newStatus === 'done') {
            $message .= "\n\nHere is their deliverable — share it with the user:\n\n{$this->task->result_summary}";
        } elseif ($this->task->result_summary) {
            $message .= "\n\nDetails: {$this->task->result_summary}";
        }

        $escapedMessage = str_replace(['"', '$', '`'], ['\\"', '\\$', '\\`'], $message);
        $agentId = $this->agent->harness_agent_id;

        $sshService->connect($this->agent->server);

        try {
            $sessionId = $this->resolveDeliverySession($sshService, $agentId);

            if (! $sessionId) {
                Log::info("Skipping delegator notification — no delivery session found for agent {$this->agent->id}");

                return;
            }

            $command = "openclaw agent --session-id {$sessionId} --message \"{$escapedMessage}\" --deliver";
            $sshService->exec($command);

            Log::info("Notified delegator agent {$this->agent->id} about task {$this->task->id} ({$this->newStatus})");
        } catch (\RuntimeException $e) {
            Log::warning("Failed to notify delegator agent {$this->agent->id}: {$e->getMessage()}");
        } finally {
            $sshService->disconnect();
        }
    }

    /**
     * Find the channel session ID for the delegator agent.
     * Uses cached chat_id when available, otherwise queries OpenClaw sessions.
     */
    private function resolveDeliverySession(SshService $sshService, string $agentId): ?string
    {
        // Telegram — fast path with cached chat_id
        if ($this->agent->telegramConnection?->bot_token) {
            $chatId = $this->agent->telegramConnection->last_chat_id;

            if (! $chatId) {
                $chatId = $this->discoverChatIdFromSessions($sshService, $agentId, 'telegram');
            }

            if ($chatId) {
                return $this->findSessionId($sshService, $agentId, "telegram:direct:{$chatId}");
            }
        }

        // Slack
        if ($this->agent->slackConnection?->bot_token) {
            return $this->findSessionId($sshService, $agentId, 'slack:');
        }

        // Discord
        if ($this->agent->discordConnection?->token) {
            return $this->findSessionId($sshService, $agentId, 'discord:');
        }

        return null;
    }

    /**
     * Query OpenClaw sessions and find the session ID matching the channel pattern.
     */
    private function findSessionId(SshService $sshService, string $agentId, string $keyPattern): ?string
    {
        $output = $sshService->exec("openclaw sessions --agent {$agentId} --json 2>/dev/null");
        $data = json_decode($output, true);

        if (! is_array($data) || empty($data['sessions'])) {
            return null;
        }

        foreach ($data['sessions'] as $session) {
            if (str_contains($session['key'] ?? '', $keyPattern)) {
                return $session['sessionId'] ?? null;
            }
        }

        return null;
    }

    /**
     * Extract chat_id from OpenClaw session keys and cache it for future notifications.
     */
    private function discoverChatIdFromSessions(SshService $sshService, string $agentId, string $channel): ?string
    {
        $output = $sshService->exec("openclaw sessions --agent {$agentId} --json 2>/dev/null");
        $data = json_decode($output, true);

        if (! is_array($data) || empty($data['sessions'])) {
            return null;
        }

        foreach ($data['sessions'] as $session) {
            $key = $session['key'] ?? '';

            // Session key format: agent:{id}:telegram:direct:{chatId}
            if (preg_match("/{$channel}:direct:(\d+)/", $key, $matches)) {
                $chatId = $matches[1];

                // Cache for future notifications
                if ($channel === 'telegram' && $this->agent->telegramConnection) {
                    $this->agent->telegramConnection->update(['last_chat_id' => $chatId]);
                    Log::info("Cached Telegram chat_id {$chatId} for agent {$this->agent->id}");
                }

                return $chatId;
            }
        }

        return null;
    }
}
