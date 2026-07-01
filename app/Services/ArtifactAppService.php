<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\AgentArtifact;
use App\Models\Server;

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

        $executor = $this->harness->resolveExecutor($server);
        $unit = $this->unitName($agent, $artifact);

        $executor->writeFile("/etc/systemd/system/{$unit}", $this->buildUnit($agent, $artifact));
        $executor->exec('systemctl daemon-reload');
        $executor->exec("systemctl enable --now {$unit}");
        $executor->exec("systemctl restart {$unit}");
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

        $executor->exec("systemctl disable --now {$unit} 2>/dev/null || true");
        $executor->exec("rm -f /etc/systemd/system/{$unit}");
        $executor->exec('systemctl daemon-reload');
    }

    public function buildUnit(Agent $agent, AgentArtifact $artifact): string
    {
        $workdir = "/root/.openclaw/agents/{$agent->harness_agent_id}/public/{$artifact->source_dir}";
        $command = $artifact->start_command;
        $port = $artifact->port;
        $description = "Provision artifact {$agent->slug}/{$artifact->path_slug}";

        return <<<UNIT
        [Unit]
        Description={$description}
        After=network.target

        [Service]
        WorkingDirectory={$workdir}
        Environment=PORT={$port}
        ExecStart=/bin/bash -lc '{$command}'
        Restart=always
        RestartSec=3

        [Install]
        WantedBy=multi-user.target
        UNIT;
    }

    public function unitName(Agent $agent, AgentArtifact $artifact): string
    {
        return "provision-artifact-{$agent->slug}-{$artifact->path_slug}.service";
    }
}
