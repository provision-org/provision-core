<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\AgentArtifact;
use App\Models\Server;
use RuntimeException;

/**
 * Manages long-running "app" artifacts: a systemd unit per artifact runs the
 * agent's start command on an allocated port, which Caddy reverse-proxies to.
 */
class ArtifactAppService
{
    /** Ports for app artifacts start here; kept clear of VNC/CDP/websockify ranges. */
    private const PORT_BASE = 7000;

    public function __construct(private HarnessManager $harness) {}

    /**
     * Allocate the next free app port on a server (unique across all agents on it).
     */
    public function allocatePort(Server $server): int
    {
        $max = AgentArtifact::query()
            ->whereHas('agent', fn ($q) => $q->where('server_id', $server->id))
            ->whereNotNull('port')
            ->max('port');

        return max($max + 1, self::PORT_BASE);
    }

    /**
     * Write and (re)start the artifact's systemd unit.
     */
    public function deploy(Agent $agent, AgentArtifact $artifact): void
    {
        $server = $agent->server;
        if (! $server) {
            return;
        }

        $port = $artifact->port;
        if (! is_int($port) || $port < 1 || $port > 65535) {
            throw new RuntimeException('App artifacts require an allocated TCP port before deployment.');
        }

        $executor = $this->harness->resolveExecutor($server);
        $unit = $this->unitName($agent, $artifact);
        $quotedUnit = escapeshellarg($unit);

        $executor->writeFile("/etc/systemd/system/{$unit}", $this->buildUnit($agent, $artifact));
        $executor->exec('systemctl daemon-reload');
        $executor->exec("systemctl enable --now {$quotedUnit}");
        $executor->exec("systemctl restart {$quotedUnit}");
        $executor->exec($this->readinessCommand($quotedUnit, $port));
    }

    /**
     * Stop and remove the artifact's systemd unit.
     */
    public function remove(Agent $agent, AgentArtifact $artifact): void
    {
        $server = $agent->server;
        if (! $server) {
            return;
        }

        $executor = $this->harness->resolveExecutor($server);
        $unit = $this->unitName($agent, $artifact);
        $unitPath = "/etc/systemd/system/{$unit}";
        $quotedUnit = escapeshellarg($unit);
        $quotedUnitPath = escapeshellarg($unitPath);

        // The condition makes repeat removal a no-op. If the unit exists or is
        // still active, however, a stop failure must abort before its file is
        // deleted so a running process is never orphaned from systemd.
        $executor->exec(
            "if [ -e {$quotedUnitPath} ] || systemctl is-active --quiet {$quotedUnit}; "
            ."then systemctl disable --now {$quotedUnit}; fi",
        );
        $executor->exec(
            "if systemctl is-active --quiet {$quotedUnit}; then "
            ."echo 'Artifact unit is still active.' >&2; exit 1; fi",
        );
        $executor->exec("rm -f {$quotedUnitPath}");
        $executor->exec('systemctl daemon-reload');
    }

    /**
     * Remove every app unit owned by an agent, including units whose artifact
     * database row no longer exists.
     */
    public function removeAgent(Agent $agent): void
    {
        $server = $agent->server;
        if (! $server) {
            return;
        }

        $slug = $agent->slug;
        if (! is_string($slug)
            || ! preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/', $slug)) {
            throw new RuntimeException('Cannot remove artifact units for an invalid agent slug.');
        }

        $executor = $this->harness->resolveExecutor($server);
        $unitPattern = "provision-artifact-{$slug}-*.service";
        $unitPathPattern = "/etc/systemd/system/{$unitPattern}";

        $executor->exec(
            "for unit_path in {$unitPathPattern}; do "
            .'[ -e "$unit_path" ] || [ -L "$unit_path" ] || continue; '
            .'unit=${unit_path##*/}; '
            ."case \"\$unit\" in {$unitPattern}) ;; *) exit 1 ;; esac; "
            .'systemctl disable --now "$unit" || exit 1; '
            .'if systemctl is-active --quiet "$unit"; then '
            .'echo "Artifact unit $unit is still active." >&2; exit 1; fi; '
            .'rm -f -- "$unit_path" || exit 1; '
            .'done',
        );
        $executor->exec('systemctl daemon-reload');
    }

    public function buildUnit(Agent $agent, AgentArtifact $artifact): string
    {
        $this->assertSafeRuntimePaths($agent, $artifact);

        $workdir = "/root/.openclaw/agents/{$agent->harness_agent_id}/public/{$artifact->source_dir}";
        $command = $artifact->start_command;
        $encodedCommand = base64_encode((string) $command);
        $port = $artifact->port;
        $description = "Provision artifact {$agent->slug}/{$artifact->path_slug}";

        return <<<UNIT
        [Unit]
        Description={$description}
        After=network.target

        [Service]
        WorkingDirectory={$workdir}
        Environment=PORT={$port}
        ExecStart=/bin/bash -lc 'printf %s {$encodedCommand} | base64 --decode | /bin/bash'
        Restart=always
        RestartSec=3

        [Install]
        WantedBy=multi-user.target
        UNIT;
    }

    public function unitName(Agent $agent, AgentArtifact $artifact): string
    {
        $this->assertSafeAgentSlug($agent);

        if (! is_string($artifact->id)
            || ! preg_match('/\A[a-z0-9]{26}\z/', $artifact->id)) {
            throw new RuntimeException('Artifact id is invalid.');
        }

        if ($artifact->deployment_key
            && ! preg_match('/\A[a-z0-9]{1,32}\z/', $artifact->deployment_key)) {
            throw new RuntimeException('Artifact deployment key is invalid.');
        }

        $deployment = $artifact->deployment_key ? "-{$artifact->deployment_key}" : '';

        return "provision-artifact-{$agent->slug}-{$artifact->id}{$deployment}.service";
    }

    /**
     * Remove every revision of one artifact after its public route is gone.
     */
    public function removeArtifact(Agent $agent, AgentArtifact $artifact): void
    {
        $this->removeArtifactUnits($agent, $artifact);
    }

    /**
     * Remove obsolete revisions while preserving the newly active unit.
     */
    public function removeStaleRevisions(Agent $agent, AgentArtifact $artifact): void
    {
        $this->removeArtifactUnits($agent, $artifact, $this->unitName($agent, $artifact));
    }

    private function readinessCommand(string $quotedUnit, int $port): string
    {
        return 'for attempt in 1 2 3 4 5 6 7 8 9 10; do '
            ."if systemctl is-active --quiet {$quotedUnit} "
            ."&& ss -H -ltn \"sport = :{$port}\" | grep -q .; then exit 0; fi; "
            .'sleep 1; done; '
            ."systemctl status --no-pager --full {$quotedUnit} >&2 || true; "
            ."echo 'Artifact app did not become active and listen on PORT {$port}.' >&2; exit 1";
    }

    private function removeArtifactUnits(
        Agent $agent,
        AgentArtifact $artifact,
        ?string $keepUnit = null,
    ): void {
        $server = $agent->server;
        if (! $server) {
            return;
        }

        $this->unitName($agent, $artifact);
        $base = "provision-artifact-{$agent->slug}-{$artifact->id}";

        $executor = $this->harness->resolveExecutor($server);
        $exactPath = escapeshellarg("/etc/systemd/system/{$base}.service");
        $revisionGlob = "/etc/systemd/system/{$base}-*.service";
        $unitPattern = "{$base}-*.service";
        $exactUnit = "{$base}.service";
        $skip = $keepUnit
            ? 'if [ "$unit" = '.escapeshellarg($keepUnit).' ]; then continue; fi; '
            : '';

        $executor->exec(
            "for unit_path in {$exactPath} {$revisionGlob}; do "
            .'[ -e "$unit_path" ] || [ -L "$unit_path" ] || continue; '
            .'unit=${unit_path##*/}; '
            ."case \"\$unit\" in {$exactUnit}|{$unitPattern}) ;; *) exit 1 ;; esac; "
            .$skip
            .'systemctl disable --now "$unit" || exit 1; '
            .'if systemctl is-active --quiet "$unit"; then '
            .'echo "Artifact unit $unit is still active." >&2; exit 1; fi; '
            .'rm -f -- "$unit_path" || exit 1; '
            .'done',
        );
        $executor->exec('systemctl daemon-reload');
    }

    private function assertSafeRuntimePaths(Agent $agent, AgentArtifact $artifact): void
    {
        $this->assertSafeAgentSlug($agent);

        if (! is_string($agent->harness_agent_id)
            || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_-]{0,127}\z/', $agent->harness_agent_id) !== 1) {
            throw new RuntimeException('Agent runtime id is invalid.');
        }

        if (! is_string($artifact->path_slug)
            || preg_match('/\A[a-z0-9][a-z0-9-]{0,59}\z/', $artifact->path_slug) !== 1) {
            throw new RuntimeException('Artifact path slug is invalid.');
        }

        if (! is_string($artifact->source_dir)
            || preg_match('/\A[a-z0-9][a-z0-9._-]*(?:\/[a-z0-9][a-z0-9._-]*)*\z/', $artifact->source_dir) !== 1) {
            throw new RuntimeException('Artifact source directory is invalid.');
        }
    }

    private function assertSafeAgentSlug(Agent $agent): void
    {
        if (! is_string($agent->slug)
            || ! preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/', $agent->slug)) {
            throw new RuntimeException('Cannot manage artifact units for an invalid agent slug.');
        }
    }
}
