<?php

namespace App\Jobs;

use App\Events\AgentUpdatedEvent;
use App\Models\Agent;
use App\Services\HarnessManager;
use App\Services\SshService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class UpdateAgentOnServerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public Agent $agent) {}

    public function handle(HarnessManager $harnessManager): void
    {
        $executor = $harnessManager->resolveExecutor($this->agent->server);

        try {
            $driver = $harnessManager->forAgent($this->agent);
            $driver->updateAgent($this->agent, $executor);
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
