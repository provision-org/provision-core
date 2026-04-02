<?php

namespace App\Jobs;

use App\Contracts\CommandExecutor;
use App\Enums\HarnessType;
use App\Models\Server;
use App\Services\ConfigPatchService;
use App\Services\HarnessManager;
use App\Services\SshService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class RemoveAgentFromServerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(
        public Server $server,
        public string $harnessAgentId,
        public bool $hasSlack,
        public HarnessType $harnessType = HarnessType::OpenClaw,
    ) {}

    public function handle(HarnessManager $harnessManager, ConfigPatchService $configPatchService): void
    {
        $executor = $harnessManager->resolveExecutor($this->server);

        try {
            match ($this->harnessType) {
                HarnessType::OpenClaw => $this->removeOpenClawAgent($executor, $configPatchService),
                HarnessType::Hermes => $this->removeHermesAgent($executor),
            };
        } finally {
            if ($executor instanceof SshService) {
                $executor->disconnect();
            }
        }
    }

    private function removeOpenClawAgent(CommandExecutor $executor, ConfigPatchService $configPatchService): void
    {
        $executor->exec($configPatchService->buildRemoveAgentPatch($this->harnessAgentId));

        if ($this->hasSlack) {
            $executor->exec($configPatchService->buildRemoveSlackTokensPatch());
        }

        $agentDir = "/root/.openclaw/agents/{$this->harnessAgentId}";
        $executor->exec("rm -rf {$agentDir}");

        RestartGatewayJob::dispatch($this->server);
    }

    private function removeHermesAgent(CommandExecutor $executor): void
    {
        $hermesHome = "/root/.hermes-{$this->harnessAgentId}";

        try {
            $executor->exec("export HERMES_HOME={$hermesHome} XDG_RUNTIME_DIR=/run/user/\$(id -u) && /root/.local/bin/hermes gateway stop 2>/dev/null || true");
            $executor->exec("export HERMES_HOME={$hermesHome} XDG_RUNTIME_DIR=/run/user/\$(id -u) && /root/.local/bin/hermes gateway uninstall 2>/dev/null || true");
            $executor->exec("rm -rf {$hermesHome}");
        } catch (\RuntimeException $e) {
            Log::warning("Failed to fully remove Hermes agent {$this->harnessAgentId}: {$e->getMessage()}");
        }
    }
}
