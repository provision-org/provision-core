<?php

namespace App\Services;

use App\Enums\ArtifactType;
use App\Models\Agent;
use App\Models\AgentArtifact;
use Illuminate\Support\Collection;

/**
 * Manages the per-agent Caddy site file that serves published artifacts at
 * {agent.slug}.{artifact_domain}. The whole site block is rebuilt from the
 * agent's live artifacts on every publish/unpublish, then Caddy is reloaded.
 */
class CaddyArtifactService
{
    public function __construct(private HarnessManager $harness) {}

    /**
     * Rebuild and write the agent's Caddy site file from its live artifacts,
     * then reload Caddy. Removes the file when the agent has no live artifacts.
     */
    public function syncAgent(Agent $agent): void
    {
        $server = $agent->server;
        if (! $server) {
            return;
        }

        $liveArtifacts = $agent->artifacts()->where('status', 'live')->get();
        $executor = $this->harness->resolveExecutor($server);
        $sitePath = $this->sitePath($agent);

        if ($liveArtifacts->isEmpty()) {
            $executor->exec("rm -f {$sitePath}");
        } else {
            $executor->writeFile($sitePath, $this->buildSiteConfig($agent, $liveArtifacts));
        }

        $executor->exec('systemctl reload caddy 2>/dev/null || caddy reload --config /etc/caddy/Caddyfile 2>/dev/null || true');
    }

    /**
     * Remove the agent's Caddy site file entirely and reload (teardown).
     */
    public function removeAgent(Agent $agent): void
    {
        $server = $agent->server;
        if (! $server) {
            return;
        }

        $executor = $this->harness->resolveExecutor($server);
        $executor->exec("rm -f {$this->sitePath($agent)}");
        $executor->exec('systemctl reload caddy 2>/dev/null || true');
    }

    /**
     * Build the Caddy site block serving all of the agent's live artifacts.
     *
     * @param  Collection<int, AgentArtifact>  $artifacts
     */
    public function buildSiteConfig(Agent $agent, Collection $artifacts): string
    {
        $lines = [];
        $lines[] = "{$agent->artifactSubdomain()} {";
        $lines[] = '    tls {';
        $lines[] = '        on_demand';
        $lines[] = '    }';

        foreach ($artifacts as $artifact) {
            $lines[] = '';
            foreach ($this->handleBlock($agent, $artifact) as $line) {
                $lines[] = $line === '' ? '' : "    {$line}";
            }
        }

        $lines[] = '}';

        return implode("\n", $lines)."\n";
    }

    /**
     * @return list<string>
     */
    private function handleBlock(Agent $agent, AgentArtifact $artifact): array
    {
        $path = $artifact->path_slug;

        if ($artifact->type === ArtifactType::App) {
            return [
                "handle_path /{$path}/* {",
                "    reverse_proxy localhost:{$artifact->port}",
                '}',
            ];
        }

        $root = "{$this->agentDir($agent)}/public/{$artifact->source_dir}";

        return [
            "handle_path /{$path}/* {",
            "    root * {$root}",
            '    file_server',
            '}',
        ];
    }

    private function sitePath(Agent $agent): string
    {
        return "/etc/caddy/sites/{$agent->slug}.caddy";
    }

    private function agentDir(Agent $agent): string
    {
        return "/root/.openclaw/agents/{$agent->harness_agent_id}";
    }
}
