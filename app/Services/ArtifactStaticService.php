<?php

namespace App\Services;

use App\Contracts\CommandExecutor;
use App\Models\Agent;
use App\Models\AgentArtifact;
use Throwable;

/**
 * Copies explicitly published static files into a Caddy-readable tree.
 *
 * Caddy runs as an unprivileged system user and cannot traverse /root. Keeping
 * a separate tree also avoids granting it access to agent credentials.
 */
class ArtifactStaticService
{
    public const PUBLISH_ROOT = '/srv/provision-artifacts';

    public function __construct(private HarnessManager $harness) {}

    public function deploy(Agent $agent, AgentArtifact $artifact): void
    {
        $server = $agent->server;
        if (! $server) {
            return;
        }

        $this->assertSafeIdentifiers($agent, $artifact);
        $this->assertSafeRuntimeSource($agent, $artifact);

        $executor = $this->harness->resolveExecutor($server);
        $source = $this->sourceDirectory($agent, $artifact);
        $parent = $this->artifactDirectory($agent, $artifact);
        $target = $this->publishedDirectory($agent, $artifact);
        $candidate = $target.'.candidate-'.bin2hex(random_bytes(8));
        $backup = $target.'.backup-'.bin2hex(random_bytes(8));
        $quotedSource = escapeshellarg($source);
        $quotedParent = escapeshellarg($parent);
        $quotedTarget = escapeshellarg($target);
        $quotedCandidate = escapeshellarg($candidate);
        $quotedBackup = escapeshellarg($backup);
        $activeMoved = false;
        $cleanupBackup = true;

        try {
            $executor->exec("test -d {$quotedSource}");
            $executor->exec("if find {$quotedSource} -mindepth 1 ! \\( -type f -o -type d \\) -print -quit | grep -q .; then echo 'Artifact source contains unsupported file types.' >&2; exit 1; fi");
            $executor->exec("install -d -m 0755 {$quotedParent}");
            $executor->exec("install -d -m 0755 {$quotedCandidate}");
            $executor->exec("cp -a {$quotedSource}/. {$quotedCandidate}/");
            $executor->exec("find {$quotedCandidate} -type d -exec chmod 0755 {} + && find {$quotedCandidate} -type f -exec chmod 0644 {} +");
            $executor->exec("if [ -e {$quotedTarget} ]; then mv {$quotedTarget} {$quotedBackup}; fi");
            $activeMoved = true;
            $executor->exec("mv {$quotedCandidate} {$quotedTarget}");
        } catch (Throwable $exception) {
            if ($activeMoved) {
                $cleanupBackup = $this->restorePublishedDirectory($executor, $quotedTarget, $quotedBackup);
            } else {
                // If the command that moved the active revision succeeded but
                // its connection failed, retain the backup for manual recovery.
                $cleanupBackup = false;
            }

            throw $exception;
        } finally {
            $this->removeDirectories(
                $executor,
                ...($cleanupBackup ? [$candidate, $backup] : [$candidate]),
            );
        }
    }

    public function remove(Agent $agent, AgentArtifact $artifact): void
    {
        $server = $agent->server;
        if (! $server) {
            return;
        }

        $executor = $this->harness->resolveExecutor($server);
        $target = escapeshellarg($this->publishedDirectory($agent, $artifact));

        $executor->exec("rm -rf -- {$target}");
    }

    /**
     * Remove every staged revision of one artifact after its route is gone.
     */
    public function removeArtifact(Agent $agent, AgentArtifact $artifact): void
    {
        $server = $agent->server;
        if (! $server) {
            return;
        }

        $this->assertSafeIdentifiers($agent, $artifact);
        $executor = $this->harness->resolveExecutor($server);
        $target = escapeshellarg($this->artifactDirectory($agent, $artifact));

        $executor->exec("rm -rf -- {$target}");
    }

    /**
     * Remove staged revisions superseded by the active deployment key.
     */
    public function removeStaleRevisions(Agent $agent, AgentArtifact $artifact): void
    {
        $server = $agent->server;
        if (! $server) {
            return;
        }

        $this->assertSafeIdentifiers($agent, $artifact);
        $executor = $this->harness->resolveExecutor($server);
        $parent = escapeshellarg($this->artifactDirectory($agent, $artifact));
        $active = escapeshellarg((string) $artifact->deployment_key);

        $executor->exec(
            "if [ -d {$parent} ]; then find {$parent} -mindepth 1 -maxdepth 1 "
            ."-type d ! -name {$active} -exec rm -rf -- {} +; fi",
        );
    }

    public function removeAgent(Agent $agent): void
    {
        $server = $agent->server;
        if (! $server) {
            return;
        }

        $this->assertSafeAgentSlug($agent);

        $executor = $this->harness->resolveExecutor($server);
        $target = escapeshellarg($this->agentDirectory($agent));

        $executor->exec("rm -rf -- {$target}");
    }

    public function publishedDirectory(Agent $agent, AgentArtifact $artifact): string
    {
        $this->assertSafeIdentifiers($agent, $artifact);

        return $this->artifactDirectory($agent, $artifact)."/{$artifact->deployment_key}";
    }

    private function sourceDirectory(Agent $agent, AgentArtifact $artifact): string
    {
        return "/root/.openclaw/agents/{$agent->harness_agent_id}/public/{$artifact->source_dir}";
    }

    private function agentDirectory(Agent $agent): string
    {
        return self::PUBLISH_ROOT."/{$agent->slug}";
    }

    private function artifactDirectory(Agent $agent, AgentArtifact $artifact): string
    {
        return $this->agentDirectory($agent)."/{$artifact->id}";
    }

    private function restorePublishedDirectory(
        CommandExecutor $executor,
        string $quotedTarget,
        string $quotedBackup,
    ): bool {
        try {
            $executor->exec("rm -rf -- {$quotedTarget}; if [ -e {$quotedBackup} ]; then mv {$quotedBackup} {$quotedTarget}; fi");
        } catch (Throwable) {
            // Preserve the original staging failure.
            return false;
        }

        return true;
    }

    private function removeDirectories(CommandExecutor $executor, string ...$paths): void
    {
        $quotedPaths = array_map(escapeshellarg(...), $paths);

        try {
            $executor->exec('rm -rf -- '.implode(' ', $quotedPaths));
        } catch (Throwable) {
            // Cleanup must not hide the staging result.
        }
    }

    private function assertSafeIdentifiers(Agent $agent, AgentArtifact $artifact): void
    {
        $this->assertSafeAgentSlug($agent);

        if (! is_string($artifact->path_slug)
            || preg_match('/\A[a-z0-9][a-z0-9-]{0,59}\z/', $artifact->path_slug) !== 1) {
            throw new \RuntimeException('Artifact path slug is invalid.');
        }

        if (! is_string($artifact->id)
            || preg_match('/\A[a-z0-9]{26}\z/', $artifact->id) !== 1) {
            throw new \RuntimeException('Artifact id is invalid.');
        }

        if (! is_string($artifact->deployment_key)
            || preg_match('/\A[a-z0-9]{1,32}\z/', $artifact->deployment_key) !== 1) {
            throw new \RuntimeException('Artifact deployment key is invalid.');
        }
    }

    private function assertSafeAgentSlug(Agent $agent): void
    {
        if (! is_string($agent->slug)
            || preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/', $agent->slug) !== 1) {
            throw new \RuntimeException('Agent slug is invalid.');
        }
    }

    private function assertSafeRuntimeSource(Agent $agent, AgentArtifact $artifact): void
    {
        if (! is_string($agent->harness_agent_id)
            || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_-]{0,127}\z/', $agent->harness_agent_id) !== 1) {
            throw new \RuntimeException('Agent runtime id is invalid.');
        }

        if (! is_string($artifact->source_dir)
            || preg_match('/\A[a-z0-9][a-z0-9._-]*(?:\/[a-z0-9][a-z0-9._-]*)*\z/', $artifact->source_dir) !== 1) {
            throw new \RuntimeException('Artifact source directory is invalid.');
        }
    }
}
