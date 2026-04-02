<?php

namespace App\Jobs;

use App\Enums\AgentStatus;
use App\Events\AgentUpdatedEvent;
use App\Models\Agent;
use App\Services\HarnessManager;
use App\Services\SshService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CreateAgentOnServerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public function __construct(public Agent $agent) {}

    public function failed(\Throwable $exception): void
    {
        Log::error('Failed to deploy agent on server', [
            'agent_id' => $this->agent->id,
            'agent_name' => $this->agent->name,
            'server_id' => $this->agent->server_id,
            'harness' => $this->agent->harness_type?->value,
            'error' => $exception->getMessage(),
        ]);

        $this->agent->update(['status' => AgentStatus::Error]);
        broadcast(new AgentUpdatedEvent($this->agent));
    }

    public function handle(HarnessManager $harnessManager): void
    {
        $executor = $harnessManager->resolveExecutor($this->agent->server);

        try {
            $driver = $harnessManager->forAgent($this->agent);
            $driver->createAgent($this->agent, $executor);
        } finally {
            if ($executor instanceof SshService) {
                $executor->disconnect();
            }
        }
    }
}
