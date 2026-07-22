<?php

namespace App\Services;

use App\Contracts\CommandExecutor;
use App\Enums\ArtifactType;
use App\Models\Agent;
use App\Models\AgentArtifact;
use App\Support\OpenClawGatewayEndpoint;
use Illuminate\Support\Collection;
use RuntimeException;
use Throwable;

/**
 * Manages the per-agent Caddy site file that serves published artifacts at
 * {agent.slug}.{artifact_domain}. The whole site block is rebuilt from the
 * agent's live artifacts on every publish/unpublish, then Caddy is reloaded.
 */
class CaddyArtifactService
{
    public function __construct(
        private HarnessManager $harness,
        private ArtifactStaticService $static,
    ) {}

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

        // Existing servers may predate artifact routing. Repair the shared root
        // atomically before claiming that a per-agent site is live.
        OpenClawGatewayEndpoint::ensureConfigured($server, $executor);
        $executor->exec('mkdir -p /etc/caddy/sites');

        if ($liveArtifacts->isEmpty()) {
            $this->removeSite($executor, $sitePath);
        } else {
            $this->replaceSite(
                $executor,
                $sitePath,
                $this->buildSiteConfig($agent, $liveArtifacts),
            );
        }
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
        $executor->exec('mkdir -p /etc/caddy/sites');
        $this->removeSite($executor, $this->sitePath($agent));
    }

    /**
     * Build the Caddy site block serving all of the agent's live artifacts.
     *
     * @param  Collection<int, AgentArtifact>  $artifacts
     */
    public function buildSiteConfig(Agent $agent, Collection $artifacts): string
    {
        $subdomain = $agent->artifactSubdomain();

        if (! $subdomain) {
            throw new RuntimeException('Artifact publishing is not configured.');
        }

        $lines = [];
        $lines[] = "{$subdomain} {";
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
        $inner = $this->serveDirectives($agent, $artifact);

        if ($artifact->isGated()) {
            // Exchange the shared-link query token for a path-scoped cookie.
            // Browser subresources do not inherit a query string, so checking
            // only ?token= would break CSS, JavaScript, images, and app APIs.
            if (! is_string($artifact->access_token) || $artifact->access_token === '') {
                throw new RuntimeException("Gated artifact {$artifact->id} has no access token.");
            }

            $cookieName = "provision_artifact_{$artifact->id}";
            $cookie = "{$cookieName}={$artifact->access_token}";
            $lines = ["handle_path /{$path}/* {"];
            $lines[] = "    @shared_link query token={$artifact->access_token}";
            $lines[] = '    handle @shared_link {';
            $lines[] = "        header Set-Cookie \"{$cookie}; Path=/{$path}/; Max-Age=2592000; Secure; HttpOnly; SameSite=Lax\"";
            $lines[] = "        redir /{$path}/ 303";
            $lines[] = '    }';
            $lines[] = "    @authorized header Cookie *{$cookie}*";
            $lines[] = '    handle @authorized {';
            foreach ($inner as $line) {
                $lines[] = "        {$line}";
            }
            $lines[] = '    }';
            $lines[] = '    handle {';
            $lines[] = '        respond "Forbidden" 403';
            $lines[] = '    }';
            $lines[] = '}';

            return $lines;
        }

        $lines = ["handle_path /{$path}/* {"];
        foreach ($inner as $line) {
            $lines[] = "    {$line}";
        }
        $lines[] = '}';

        return $lines;
    }

    /**
     * The directives that actually serve an artifact (file server or proxy).
     *
     * @return list<string>
     */
    private function serveDirectives(Agent $agent, AgentArtifact $artifact): array
    {
        if ($artifact->type === ArtifactType::App) {
            return [
                "reverse_proxy localhost:{$artifact->port} {",
                "    header_up X-Forwarded-Prefix /{$artifact->path_slug}",
                '}',
            ];
        }

        return [
            'root * '.$this->quoteCaddyValue($this->static->publishedDirectory($agent, $artifact)),
            'file_server',
        ];
    }

    private function sitePath(Agent $agent): string
    {
        return "/etc/caddy/sites/{$agent->slug}.caddy";
    }

    private function quoteCaddyValue(string $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function replaceSite(CommandExecutor $executor, string $sitePath, string $config): void
    {
        $candidate = $sitePath.'.provision-artifact-'.bin2hex(random_bytes(8));
        $quotedCandidate = escapeshellarg($candidate);

        try {
            $executor->writeFile($candidate, $config);
            $executor->exec("chmod 0644 {$quotedCandidate}");
            $executor->exec("caddy validate --config {$quotedCandidate} --adapter caddyfile");
        } catch (Throwable $exception) {
            $this->removeTemporaryFiles($executor, $candidate);
            throw $exception;
        }

        // The shared transaction owns backup, activation, full-root validation,
        // reload, and rollback while holding the same lock as root Caddy edits.
        // On an exception, retain both recovery state and the candidate because
        // the SSH outcome may be ambiguous.
        $executor->exec(OpenClawGatewayEndpoint::replacementTransaction($sitePath, $candidate));

        $this->removeTemporaryFiles($executor, $candidate);
    }

    private function removeSite(CommandExecutor $executor, string $sitePath): void
    {
        $executor->exec(OpenClawGatewayEndpoint::removalTransaction($sitePath));
    }

    private function removeTemporaryFiles(CommandExecutor $executor, string ...$paths): void
    {
        $quotedPaths = array_map(escapeshellarg(...), $paths);

        try {
            $executor->exec('rm -f '.implode(' ', $quotedPaths));
        } catch (Throwable) {
            // Cleanup must not hide the configuration or reload result.
        }
    }
}
