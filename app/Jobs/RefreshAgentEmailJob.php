<?php

namespace App\Jobs;

use App\Enums\HarnessType;
use App\Events\AgentUpdatedEvent;
use App\Models\Agent;
use App\Services\HarnessManager;
use App\Services\SshService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Re-sync an agent's server files after its email address changed.
 *
 * The normal update script writes .gitconfig and ONBOARDING.md only when
 * they're missing, so a plain re-sync would leave the OLD email in those two
 * files. This clears them first, then runs the standard update so every
 * email-bearing file (IDENTITY.md, .env, TOOLS.md, the MailboxKit skill,
 * .gitconfig, ONBOARDING.md) is regenerated from the new address.
 */
class RefreshAgentEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public Agent $agent) {}

    public function handle(HarnessManager $harnessManager): void
    {
        $server = $this->agent->server;
        if (! $server) {
            return;
        }

        $executor = $harnessManager->resolveExecutor($server);

        try {
            $agentDir = match ($this->agent->harness_type) {
                HarnessType::Hermes => "/root/.hermes-{$this->agent->harness_agent_id}",
                default => "/root/.openclaw/agents/{$this->agent->harness_agent_id}",
            };

            // Drop the guarded files so the update script regenerates them.
            $executor->exec("rm -f {$agentDir}/.gitconfig {$agentDir}/ONBOARDING.md");

            $harnessManager->forAgent($this->agent)->updateAgent($this->agent, $executor);
        } finally {
            if ($executor instanceof SshService) {
                $executor->disconnect();
            }
        }
    }

    public function failed(\Throwable $exception): void
    {
        $this->agent->update(['is_syncing' => false]);
        broadcast(new AgentUpdatedEvent($this->agent));
    }
}
