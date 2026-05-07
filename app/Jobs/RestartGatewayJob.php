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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class RestartGatewayJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Window during which back-to-back restarts collapse into one. Adding a
     * second agent dispatches three restarts in quick succession (install
     * script + rebuildAllChannelConfigs + VerifyAgentChannelsJob); without
     * coalescing, systemd hits its StartLimit and in-flight chat messages
     * get dropped during the thrash.
     */
    private const COALESCE_WINDOW_SECONDS = 25;

    public function __construct(public Server $server) {}

    public function handle(HarnessManager $harnessManager): void
    {
        $cacheKey = "gateway_restart:{$this->server->id}";

        // If another restart fired within the coalesce window, skip this one.
        // The earlier restart already picked up any config changes we'd want.
        if (Cache::has($cacheKey)) {
            Log::info("RestartGatewayJob coalesced — recent restart still cached for server {$this->server->id}");

            return;
        }

        Cache::put($cacheKey, now()->toIso8601String(), self::COALESCE_WINDOW_SECONDS);

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
