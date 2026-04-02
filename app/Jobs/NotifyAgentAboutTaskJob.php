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

class NotifyAgentAboutTaskJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct(
        public Agent $agent,
        public Task $task,
    ) {}

    public function handle(SshService $sshService): void
    {
        if ($this->agent->status !== AgentStatus::Active || ! $this->agent->server) {
            Log::info("Skipping task notification — agent {$this->agent->id} not active or no server");

            return;
        }

        $this->agent->loadMissing(['slackConnection', 'telegramConnection', 'discordConnection']);

        $agentId = $this->agent->harness_agent_id;
        $message = "New task assigned to you: \"{$this->task->title}\"";

        if ($this->task->description) {
            $message .= "\n\nDescription: {$this->task->description}";
        }

        $message .= "\n\nRun `tasks_list --assigned mine` to see your tasks and claim this one.";

        $escapedMessage = str_replace('"', '\\"', $message);

        $command = "openclaw agent --agent {$agentId} --message \"{$escapedMessage}\" --deliver";

        // Resolve reply channel from agent connections (Slack > Telegram > Discord)
        $replyChannel = $this->resolveReplyChannel();
        if ($replyChannel) {
            $command .= " --reply-channel {$replyChannel['channel']} --reply-account {$replyChannel['account']}";
        }

        $sshService->connect($this->agent->server);

        try {
            $sshService->exec($command);
            Log::info("Notified agent {$this->agent->id} about task {$this->task->id}");
        } catch (\RuntimeException $e) {
            Log::warning("Failed to notify agent {$this->agent->id} about task: {$e->getMessage()}");
        } finally {
            $sshService->disconnect();
        }
    }

    /**
     * Resolve the best reply channel for the agent.
     *
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
