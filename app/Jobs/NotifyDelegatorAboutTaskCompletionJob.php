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

        $this->agent->loadMissing(['slackConnection', 'telegramConnection', 'discordConnection']);
        $this->task->loadMissing('agent');

        $executorName = $this->task->agent?->name ?? 'Unknown agent';
        $agentId = $this->agent->harness_agent_id;

        $message = match ($this->newStatus) {
            'done' => "Task completed by {$executorName}: \"{$this->task->title}\"",
            'failed' => "Task failed by {$executorName}: \"{$this->task->title}\"",
            'blocked' => "Task blocked by {$executorName}: \"{$this->task->title}\"",
            default => "Task status changed to {$this->newStatus}: \"{$this->task->title}\"",
        };

        if ($this->task->result_summary) {
            $message .= "\n\nSummary: {$this->task->result_summary}";
        }

        $escapedMessage = str_replace('"', '\\"', $message);

        $command = "openclaw agent --agent {$agentId} --message \"{$escapedMessage}\" --deliver";

        $replyChannel = $this->resolveReplyChannel();
        if ($replyChannel) {
            $command .= " --reply-channel {$replyChannel['channel']} --reply-account {$replyChannel['account']}";
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

    /**
     * @return array{channel: string, account: string}|null
     */
    private function resolveReplyChannel(): ?array
    {
        $agentId = $this->agent->harness_agent_id;

        if ($this->agent->slackConnection?->bot_token) {
            return ['channel' => 'slack', 'account' => "slack-{$agentId}"];
        }

        if ($this->agent->telegramConnection?->bot_token) {
            return ['channel' => 'telegram', 'account' => "telegram-{$agentId}"];
        }

        if ($this->agent->discordConnection?->token) {
            return ['channel' => 'discord', 'account' => "discord-{$agentId}"];
        }

        return null;
    }
}
