<?php

namespace App\Jobs;

use App\Contracts\CommandExecutor;
use App\Enums\HarnessType;
use App\Models\Server;
use App\Services\HarnessManager;
use App\Services\SshService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RestartGatewayJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public Server $server) {}

    public function handle(HarnessManager $harnessManager): void
    {
        $executor = $harnessManager->resolveExecutor($this->server);

        try {
            $agentsByHarness = $this->server->agents()->get()->groupBy(
                fn ($agent) => $agent->harness_type->value
            );

            // Restart OpenClaw gateway (single gateway for all OpenClaw agents)
            if ($agentsByHarness->has('openclaw')) {
                $harnessManager->driver(HarnessType::OpenClaw)->restartGateway($this->server, $executor);
            }

            // Restart each Hermes agent's individual gateway
            if ($agentsByHarness->has('hermes')) {
                $harnessManager->driver(HarnessType::Hermes)->restartGateway($this->server, $executor);
            }

            // Restart the managed browser service (shared across all agents)
            $this->startBrowserService($executor);

            $this->server->events()->create([
                'event' => 'gateway_restarted',
                'payload' => ['harnesses' => $agentsByHarness->keys()->all()],
            ]);
        } finally {
            if ($executor instanceof SshService) {
                $executor->disconnect();
            }
        }
    }

    private function startBrowserService(CommandExecutor $executor): void
    {
        try {
            $executor->exec('openclaw browser --browser-profile openclaw start 2>&1');
        } catch (\RuntimeException $e) {
            Log::warning("Failed to start browser service on server {$this->server->id}: {$e->getMessage()}");
        }
    }
}
