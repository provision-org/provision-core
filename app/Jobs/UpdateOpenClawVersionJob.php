<?php

namespace App\Jobs;

use App\Contracts\CommandExecutor;
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

    /**
     * Convert the legacy persistent-volume symlinks to bind mounts.
     * Idempotent; no-op on servers without the volume layout.
     */
    private const SYMLINK_TO_BIND_MOUNT_SCRIPT = 'if [ -d /mnt/openclaw-data ]; then for D in agents logs; do if [ -L "/root/.openclaw/$D" ]; then rm "/root/.openclaw/$D"; fi; mkdir -p "/root/.openclaw/$D" "/mnt/openclaw-data/$D"; mountpoint -q "/root/.openclaw/$D" || mount --bind "/mnt/openclaw-data/$D" "/root/.openclaw/$D"; grep -q " /root/.openclaw/$D " /etc/fstab || echo "/mnt/openclaw-data/$D /root/.openclaw/$D none bind,nofail 0 0" >> /etc/fstab; done; fi';

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
                // Converged binary is not a converged box: an interrupted
                // earlier run can leave the package updated but the gateway
                // refusing to boot on an unmigrated legacy session store
                // (2026.8.1 startup gate). Repair before declaring victory.
                $this->repairGatewayIfDown($executor);
                $this->recordVersion($installed);

                return;
            }

            // --tag both upgrades and rolls back to the exact pin; the updater
            // verifies the candidate with doctor and restores the previous
            // package if activation fails, so a failed run leaves the box on
            // its current version. A real upgrade downloads + stages the npm
            // package — minutes, not seconds — so it needs an explicit long
            // timeout: at the 30s default phpseclib times out mid-command and
            // leaves the SSH channel open, poisoning every subsequent exec on
            // the connection (E2E-verified failure mode).
            $this->execLong($executor, sprintf(
                'openclaw update --tag %s --yes --json 2>&1',
                escapeshellarg($pinnedVersion),
            ), 600);

            // 2026.8.1's gateway refuses to boot while a legacy sessions.json
            // exists (session store moved to per-agent SQLite); doctor
            // migrates it. Plugins installed pre-2026.8.1 also need a one-time
            // consented update or they stay pinned old.
            $this->runDoctorMigration($executor);
            $this->execLong($executor, 'openclaw plugins update --all --accept-capabilities 2>&1 || true', 600);
            // Bundled plugins (e.g. perplexity) can lack recorded capability
            // consent after an upgrade — gateway then refuses readiness with
            // "plugin verification failed". `update repair` records consent.
            $this->execLong($executor, 'openclaw update repair --accept-capabilities --yes 2>&1 || true', 300);
            $this->execLong($executor, 'export XDG_RUNTIME_DIR=/run/user/$(id -u) && systemctl --user reset-failed openclaw-gateway 2>/dev/null; systemctl --user restart openclaw-gateway 2>&1 || true', 60);

            $after = $this->parseVersion($this->execLong($executor, 'openclaw --version 2>/dev/null || true', 60));

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
     * If the gateway service is not active (e.g. crash-looping on the
     * 2026.8.1 legacy-session-store startup gate), run the doctor migration
     * and restart it. Best-effort: any failure surfaces via the next health
     * check rather than failing the version job.
     */
    private function repairGatewayIfDown(CommandExecutor $executor): void
    {
        $state = trim($this->execLong(
            $executor,
            'export XDG_RUNTIME_DIR=/run/user/$(id -u) && systemctl --user is-active openclaw-gateway 2>&1 || true',
            30,
        ));

        if ($state === 'active') {
            return;
        }

        Log::warning('OpenClaw binary matches pin but gateway is not active — running doctor repair.', [
            'server_id' => $this->server->id,
            'state' => $state,
        ]);

        $this->runDoctorMigration($executor);
    }

    /**
     * Run the 2026.8.1 legacy-store migration correctly. Two E2E-verified
     * requirements: (1) the volume dirs must be bind mounts, not symlinks —
     * the SQLite import refuses symbolic-link path components; (2) the
     * gateway must be STOPPED while doctor runs — a live (even crash-looping)
     * gateway owns the state-dir lock and doctor silently skips automatic
     * state migrations.
     */
    private function runDoctorMigration(CommandExecutor $executor): void
    {
        $this->execLong($executor, self::SYMLINK_TO_BIND_MOUNT_SCRIPT, 60);
        $this->execLong($executor, 'export XDG_RUNTIME_DIR=/run/user/$(id -u) && systemctl --user stop openclaw-gateway 2>&1; systemctl --user reset-failed openclaw-gateway 2>/dev/null || true', 60);
        $this->execLong($executor, 'openclaw doctor --fix --non-interactive 2>&1 || true', 300);
        $this->execLong($executor, 'openclaw update repair --accept-capabilities --yes 2>&1 || true', 300);
        $this->execLong($executor, 'export XDG_RUNTIME_DIR=/run/user/$(id -u) && systemctl --user start openclaw-gateway 2>&1 || true', 60);
    }

    /**
     * Run a potentially long command with an explicit SSH timeout. The
     * CommandExecutor contract has no timeout parameter, so pass it only on
     * the SSH implementation; DockerExecutor runs locally and does not hold
     * a channel open across commands.
     */
    private function execLong(CommandExecutor $executor, string $command, int $timeoutSeconds): string
    {
        if ($executor instanceof SshService) {
            return $executor->exec($command, $timeoutSeconds);
        }

        return $executor->exec($command);
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
