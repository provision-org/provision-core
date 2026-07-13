<?php

namespace App\Console\Commands;

use App\Contracts\CommandExecutor;
use App\Enums\AgentStatus;
use App\Enums\HarnessType;
use App\Enums\ServerStatus;
use App\Models\Agent;
use App\Models\Server;
use App\Services\AgentInstallScriptService;
use App\Services\HarnessManager;
use App\Services\SshService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Reconcile the per-agent Caddy browser route on agent servers to the agent's
 * current (frozen) browser profile name.
 *
 * Agents renamed before browser_profile_name was frozen have a Caddy route
 * under their old name, so the dashboard browser panel renders black. This
 * rewrites the route under the correct name (idempotent) and, with --prune,
 * removes orphaned browser route files.
 */
class RepairBrowserRoutes extends Command
{
    protected $signature = 'agents:repair-browser-routes
        {--agent= : Limit to a single agent id}
        {--prune : Remove orphaned /browser/* route files with no matching live agent}
        {--dry-run : Report what would change without touching any server}';

    protected $description = 'Reconcile per-agent Caddy browser routes to the frozen profile name';

    public function handle(HarnessManager $harness): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $agents = Agent::query()
            ->where('harness_type', HarnessType::OpenClaw)
            ->where('status', AgentStatus::Active)
            ->whereNotNull('browser_display_num')
            ->when($this->option('agent'), fn ($q, $id) => $q->where('id', $id))
            ->whereHas('server', fn ($q) => $q->where('status', ServerStatus::Running))
            ->with('server')
            ->get()
            ->filter(fn (Agent $a) => $a->server && ! $a->server->isDocker())
            ->groupBy('server_id');

        if ($agents->isEmpty()) {
            $this->info('No eligible agents found.');

            return self::SUCCESS;
        }

        $changed = 0;

        foreach ($agents as $serverId => $serverAgents) {
            /** @var Server $server */
            $server = $serverAgents->first()->server;
            $this->line("Server {$server->ipv4_address} ({$serverAgents->count()} agents)");

            $executor = $harness->resolveExecutor($server);
            $desired = [];

            try {
                foreach ($serverAgents as $agent) {
                    $profile = AgentInstallScriptService::browserProfileName($agent);
                    $wsPort = 6080 + (int) $agent->browser_display_num;
                    $desired[] = $profile;
                    $path = "/etc/caddy/conf.d/{$profile}.caddy";
                    $config = "handle_path /browser/{$profile}/* {\n    reverse_proxy localhost:{$wsPort}\n}\n";

                    $existing = trim($executor->exec("cat {$path} 2>/dev/null || true"));
                    if ($existing === trim($config)) {
                        $this->line("  ✓ {$agent->name} → {$profile} (up to date)");

                        continue;
                    }

                    $changed++;
                    if ($dryRun) {
                        $this->warn("  ~ {$agent->name} → would write {$profile} (port {$wsPort})");

                        continue;
                    }

                    $executor->writeFile($path, $config);
                    $this->info("  ✓ {$agent->name} → wrote {$profile} (port {$wsPort})");
                }

                if ($this->option('prune')) {
                    $changed += $this->pruneOrphans($executor, $desired, $dryRun);
                }

                if (! $dryRun) {
                    $executor->exec('systemctl reload caddy 2>/dev/null || true');
                }
            } catch (Throwable $e) {
                $this->error("  ✗ {$server->ipv4_address}: {$e->getMessage()}");
            } finally {
                if ($executor instanceof SshService) {
                    $executor->disconnect();
                }
            }
        }

        $verb = $dryRun ? 'would change' : 'changed';
        $this->newLine();
        $this->info("Done — {$changed} route(s) {$verb}.");

        return self::SUCCESS;
    }

    /**
     * Remove /browser/* route files that don't match any live agent's profile.
     *
     * @param  list<string>  $desired
     */
    private function pruneOrphans(CommandExecutor $executor, array $desired, bool $dryRun): int
    {
        $files = array_filter(explode("\n", trim($executor->exec(
            'grep -rl "handle_path /browser/" /etc/caddy/conf.d/ 2>/dev/null || true'
        ))));

        $pruned = 0;
        foreach ($files as $file) {
            $profile = basename(trim($file), '.caddy');
            if (in_array($profile, $desired, true)) {
                continue;
            }

            $pruned++;
            if ($dryRun) {
                $this->warn("  ~ would prune orphan {$profile}");

                continue;
            }

            $executor->exec('rm -f '.escapeshellarg(trim($file)));
            $this->info("  ✓ pruned orphan {$profile}");
        }

        return $pruned;
    }
}
