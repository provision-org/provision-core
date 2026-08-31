<?php

namespace App\Jobs;

use App\Models\ChatMessage;
use App\Models\OpenClawSessionDiscovery;
use App\Models\Server;
use App\Services\HarnessManager;
use App\Services\SshService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Reconcile a server's OpenClaw install with the pinned version using the
 * official updater. `openclaw update --tag` stages the candidate, runs doctor,
 * restarts the gateway, and rolls back on failure — unlike the raw
 * `npm install -g` swap it replaces, which upstream requires stopping the
 * gateway for. Dispatched fleet-wide from the scheduler and individually
 * after a pin bump.
 */
class UpdateOpenClawVersionJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 10;

    public int $timeout = 600;

    public int $uniqueFor = 900;

    public function __construct(public Server $server) {}

    public function uniqueId(): string
    {
        return "openclaw-update:{$this->server->id}";
    }

    public function handle(HarnessManager $harnessManager): void
    {
        $lock = Cache::lock("openclaw-update-execution:{$this->server->id}", $this->timeout + 60);
        if (! $lock->get()) {
            $this->release(60);

            return;
        }

        try {
            $this->ensureCurrent($harnessManager);
        } finally {
            $lock->release();
        }
    }

    private function ensureCurrent(HarnessManager $harnessManager): void
    {
        $this->server->refresh();
        $pinnedVersion = (string) config('provision.openclaw_version');

        if ($this->serverHasActiveWork()) {
            $this->release(120);

            return;
        }

        $executor = $harnessManager->resolveExecutor($this->server);

        try {
            $installed = $this->parseVersion($executor->exec('openclaw --version 2>/dev/null || true'));

            if ($installed === null) {
                throw new RuntimeException('OpenClaw is not installed or not on PATH.');
            }

            if ($installed === $pinnedVersion) {
                $this->recordVersion($installed);

                return;
            }

            // --tag both upgrades and rolls back to the exact pin; the updater
            // verifies the candidate with doctor and restores the previous
            // package if activation fails, so a failed run leaves the box on
            // its current version.
            $executor->exec(sprintf(
                'openclaw update --tag %s --yes --json 2>&1',
                escapeshellarg($pinnedVersion),
            ));

            // 2026.8.1's gateway refuses to boot while a legacy sessions.json
            // exists (session store moved to per-agent SQLite); doctor
            // migrates it. Run before the updater-triggered restart settles so
            // the box never sits gateway-down. Plugins installed pre-2026.8.1
            // also need a one-time consented update or they stay pinned old.
            $executor->exec('openclaw doctor --fix --non-interactive 2>&1 || true');
            $executor->exec('openclaw plugins update --all --accept-capabilities 2>&1 || true');
            $executor->exec('export XDG_RUNTIME_DIR=/run/user/$(id -u) && systemctl --user restart openclaw-gateway 2>&1 || true');

            $after = $this->parseVersion($executor->exec('openclaw --version 2>/dev/null || true'));

            if ($after !== $pinnedVersion) {
                throw new RuntimeException(
                    "OpenClaw update did not converge: expected {$pinnedVersion}, found ".($after ?? 'nothing'),
                );
            }

            $this->recordVersion($after);

            Log::info('OpenClaw updated to pinned version.', [
                'server_id' => $this->server->id,
                'from' => $installed,
                'to' => $after,
            ]);
        } finally {
            if ($executor instanceof SshService) {
                $executor->disconnect();
            }
        }
    }

    /**
     * `openclaw --version` output varies by build: the live binary prints
     * "OpenClaw 2026.7.1 (2d2ddc4)" while some entry points print the bare
     * "2026.7.1". Extract the version token instead of relying on either
     * shape — positional parses (`awk '{print $2}'` / `$NF`) have each
     * silently broken against one of them.
     */
    private function parseVersion(string $output): ?string
    {
        return preg_match('/\d{4}\.\d+\.\d+(?:-[0-9A-Za-z.]+)?/', $output, $matches)
            ? $matches[0]
            : null;
    }

    private function recordVersion(string $version): void
    {
        if ($this->server->openclaw_version !== $version) {
            $this->server->forceFill(['openclaw_version' => $version])->saveQuietly();
        }
    }

    private function serverHasActiveWork(): bool
    {
        $hasFreshDaemonRun = ($this->server->last_health_check?->isAfter(now()->subMinutes(2)) ?? false)
            && ($this->server->daemon_active_runs ?? []) !== [];
        if ($hasFreshDaemonRun) {
            return true;
        }

        $hasActiveDashboardChat = ChatMessage::query()
            ->where('delivery_status', 'running')
            ->where('sent_at', '>=', now()->subMinutes(60))
            ->whereHas('conversation.agent', function ($query): void {
                $query->where('server_id', $this->server->id);
            })
            ->exists();
        if ($hasActiveDashboardChat) {
            return true;
        }

        return OpenClawSessionDiscovery::query()
            ->where('server_id', $this->server->id)
            ->where('has_active_run', true)
            ->where('discovered_at', '>=', now()->subMinutes(OpenClawSessionDiscovery::IMPORT_WINDOW_MINUTES))
            ->exists();
    }
}
