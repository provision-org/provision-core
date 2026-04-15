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

    public int $timeout = 60;

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
            'done' => "Task completed by {$executorName}: \"{$this->task->title}\"",
            'failed' => "Task failed by {$executorName}: \"{$this->task->title}\"",
            'blocked' => "Task blocked by {$executorName}: \"{$this->task->title}\"",
            default => "Task status changed to {$this->newStatus}: \"{$this->task->title}\"",
        };

        if ($this->task->result_summary) {
            $message .= "\n\nSummary: {$this->task->result_summary}";
        }

        $escapedMessage = str_replace(['"', '$', '`'], ['\\"', '\\$', '\\`'], $message);

        $command = $this->buildCommand($escapedMessage);

        if (! $command) {
            Log::info("Skipping delegator notification — no delivery channel for agent {$this->agent->id}");

            return;
        }

        $sshService->connect($this->agent->server);

        try {
            $sshService->exec($command);
            Log::info("Notified delegator agent {$this->agent->id} about task {$this->task->id} ({$this->newStatus})");
        } catch (\RuntimeException $e) {
            Log::warning("Failed to notify delegator agent {$this->agent->id}: {$e->getMessage()}");
        } finally {
            $sshService->disconnect();
        }
    }

    private function buildCommand(string $escapedMessage): ?string
    {
        $agentId = $this->agent->harness_agent_id;

        // Telegram: use --to {chatId} to target the user's Telegram session
        if ($this->agent->telegramConnection?->bot_token) {
            $chatId = $this->agent->telegramConnection->last_chat_id;

            if ($chatId) {
                return "openclaw agent --agent {$agentId} --to {$chatId} --message \"{$escapedMessage}\" --deliver --reply-channel telegram";
            }

            // Fallback: query OpenClaw sessions to find the Telegram session ID
            return $this->buildSessionFallbackCommand($agentId, $escapedMessage, 'telegram');
        }

        // Slack: use --reply-channel slack
        if ($this->agent->slackConnection?->bot_token) {
            return "openclaw agent --agent {$agentId} --message \"{$escapedMessage}\" --deliver --reply-channel slack";
        }

        // Discord: use --reply-channel discord
        if ($this->agent->discordConnection?->token) {
            return "openclaw agent --agent {$agentId} --message \"{$escapedMessage}\" --deliver --reply-channel discord";
        }

        return null;
    }

    /**
     * Fallback: query OpenClaw sessions to find the channel session ID,
     * then send with --session-id. Used when last_chat_id isn't populated yet.
     */
    private function buildSessionFallbackCommand(string $agentId, string $escapedMessage, string $channel): string
    {
        // Single command: extract session ID from JSON, then send
        return <<<BASH
SESSION_ID=\$(openclaw sessions --agent {$agentId} --json 2>/dev/null | node -e "
  let d='';process.stdin.on('data',c=>d+=c);process.stdin.on('end',()=>{
    try{const j=JSON.parse(d);const s=j.sessions.find(s=>s.key.includes('{$channel}:'));
    if(s)process.stdout.write(s.sessionId)}catch{}
  })
") && [ -n "\$SESSION_ID" ] && openclaw agent --session-id \$SESSION_ID --message "{$escapedMessage}" --deliver
BASH;
    }
}
