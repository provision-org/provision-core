<?php

namespace App\Services;

use App\Enums\AgentStatus;
use App\Enums\ArtifactType;
use App\Enums\ArtifactVisibility;
use App\Enums\HarnessType;
use App\Enums\ServerStatus;
use App\Models\Agent;
use App\Models\AgentArtifact;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Orchestrates publishing an artifact: ensure the agent's DNS record exists,
 * write the Caddy site config, and track status on the artifact row.
 */
class PublishArtifactService
{
    public function __construct(
        private CloudflareDnsService $dns,
        private CaddyArtifactService $caddy,
        private ArtifactAppService $apps,
        private ArtifactStaticService $static,
    ) {}

    /**
     * Publish (or re-publish) an artifact for an agent.
     *
     * @param  array<string, mixed>  $data
     */
    public function publish(Agent $agent, array $data): AgentArtifact
    {
        $this->assertPublishingIsSupported($agent);

        $pathSlug = $data['path_slug'];
        $sourceDir = $data['source_dir'] ?? $pathSlug;
        $type = $data['type'] ?? ArtifactType::Static;
        $type = $type instanceof ArtifactType ? $type : ArtifactType::from($type);
        $visibility = $data['visibility'] ?? ArtifactVisibility::Public;
        $visibility = $visibility instanceof ArtifactVisibility
            ? $visibility
            : ArtifactVisibility::from($visibility);
        $this->assertSafePath($pathSlug, 'path_slug', '/^[a-z0-9][a-z0-9-]*$/');
        $this->assertSafePath(
            $sourceDir,
            'source_dir',
            '/^[a-z0-9][a-z0-9._-]*(?:\/[a-z0-9][a-z0-9._-]*)*$/',
        );

        if ($type === ArtifactType::App && blank($data['start_command'] ?? null)) {
            throw ValidationException::withMessages([
                'start_command' => 'A start command is required for app artifacts.',
            ]);
        }

        return $this->withServerLock($agent, function () use ($agent, $data, $pathSlug, $sourceDir, $type, $visibility): AgentArtifact {
            $agent->refresh()->load('server');
            $this->assertPublishingIsSupported($agent);
            $agent->forceFill(['artifact_cleanup_required' => true])->save();

            $existing = $agent->artifacts()->where('path_slug', $pathSlug)->first();
            $this->assertWithinQuota($agent, $existing, $type);
            $previous = $existing ? clone $existing : null;
            $artifact = $existing ?? $agent->artifacts()->make();
            // Allocate while the previous row still owns its port. App
            // revisions overlap until Caddy switches routes, so reusing the
            // old port would make the replacement fail its readiness check.
            $port = $type === ArtifactType::App
                ? $this->apps->allocatePort($agent->server)
                : null;

            $artifact->fill([
                'team_id' => $agent->team_id,
                'path_slug' => $pathSlug,
                'name' => $data['name'],
                'type' => $type,
                'source_dir' => $sourceDir,
                'start_command' => $data['start_command'] ?? null,
                'port' => $port,
                'deployment_key' => bin2hex(random_bytes(8)),
                'visibility' => $visibility,
                'status' => 'pending',
                'error_message' => null,
            ]);

            if ($artifact->isGated()) {
                $artifact->access_token = $previous?->isGated()
                    ? $previous->access_token
                    : Str::random(40);
            } else {
                $artifact->access_token = null;
            }

            $artifact->public_url = $this->publicUrl($agent, $artifact);
            $artifact->save();

            try {
                if ($artifact->type === ArtifactType::App) {
                    $this->apps->deploy($agent, $artifact);
                } else {
                    $this->static->deploy($agent, $artifact);
                }

                $this->dns->ensureAgentRecord($agent);

                // syncAgent reads live rows, so this transition must happen
                // before its atomic candidate is built and validated.
                $artifact->update([
                    'status' => 'live',
                    'last_published_at' => now(),
                ]);
            } catch (Throwable $exception) {
                $failedDeployment = clone $artifact;
                $this->restorePreviousRevision($artifact, $previous, $exception);
                $this->removeDeploymentBestEffort($agent, $failedDeployment);

                throw $exception;
            }

            try {
                $this->caddy->syncAgent($agent);
            } catch (Throwable $exception) {
                // A transport failure is ambiguous: the remote transaction may
                // have committed and reloaded before the connection dropped.
                // Keep the new DB revision, DNS, and both deployments so either
                // the old or new Caddy route still has a live target. A later
                // publish, unpublish, or teardown will reconcile the state.
                Log::warning("Artifact route activation outcome is uncertain for {$artifact->id}: {$exception->getMessage()}");

                throw $exception;
            }

            if ($previous) {
                try {
                    $this->removeSupersededDeployments($agent, $artifact);
                } catch (Throwable $exception) {
                    // The new route is already active. Keep serving it and let
                    // agent/server teardown retry removal of the stale revision.
                    Log::warning("Previous artifact deployment cleanup failed for {$artifact->id}: {$exception->getMessage()}");
                }
            }

            return $artifact->fresh();
        });
    }

    /**
     * Unpublish an artifact: remove it, re-sync Caddy, and drop the agent's DNS
     * record once it has no live artifacts left.
     */
    public function unpublish(AgentArtifact $artifact): void
    {
        $agent = $artifact->agent;
        $artifactId = $artifact->getKey();

        $this->withServerLock($agent, function () use ($agent, $artifactId): void {
            // Route binding happened before lock acquisition. Re-query so a
            // concurrent republish cannot leave its newer revision orphaned.
            $current = $agent->artifacts()->whereKey($artifactId)->first();
            if (! $current) {
                return;
            }

            $previousStatus = $current->status;

            // Retain the row until the public route is definitely gone. If
            // Caddy rejects the candidate, restore the prior status for retry.
            $current->update(['status' => 'stopped']);

            try {
                $this->caddy->syncAgent($agent);
            } catch (Throwable $exception) {
                $current->update(['status' => $previousStatus]);

                throw $exception;
            }

            $this->removeAllDeployments($agent, $current);

            if (! $agent->artifacts()->where('status', 'live')->exists()) {
                $this->dns->removeAgentRecord($agent);
            }

            $current->delete();

            if ($agent->artifacts()->doesntExist()) {
                $agent->forceFill(['artifact_cleanup_required' => false])->save();
            }
        });
    }

    /**
     * Tear down all of an agent's artifacts when the agent itself is being
     * removed: stop app processes, drop the Caddy site file, and delete the
     * agent's DNS record. Best-effort — safe to call even with no artifacts.
     */
    public function teardownAgent(Agent $agent, bool $requireServerCleanup = true): void
    {
        $this->withServerLock($agent, function () use ($agent, $requireServerCleanup): void {
            $this->teardownAgentWithinLock($agent, $requireServerCleanup);
        });
    }

    private function teardownAgentWithinLock(Agent $agent, bool $requireServerCleanup): void
    {
        $hasArtifacts = $agent->artifacts()->exists();
        $hasDnsState = (bool) ($agent->artifact_dns_record_id
            || $agent->artifact_dns_record_name
            || $agent->artifact_dns_zone_id);

        if (! $hasArtifacts
            && ! $agent->artifact_cleanup_required
            && ! $hasDnsState) {
            return;
        }

        $serverFailures = [];
        $dnsFailures = [];

        if ($agent->server) {
            try {
                $this->caddy->removeAgent($agent);
            } catch (Throwable $exception) {
                $serverFailures[] = $exception;
            }

            try {
                $this->apps->removeAgent($agent);
            } catch (Throwable $exception) {
                $serverFailures[] = $exception;
            }

            try {
                $this->static->removeAgent($agent);
            } catch (Throwable $exception) {
                $serverFailures[] = $exception;
            }
        }

        if ($hasArtifacts || $hasDnsState) {
            try {
                // Artifact state proves a managed record may exist. Missing DNS
                // credentials must retain that state for a safe retry.
                $this->dns->removeAgentRecord($agent);
            } catch (Throwable $exception) {
                $dnsFailures[] = $exception;
            }
        }

        if (! $requireServerCleanup) {
            foreach ($serverFailures as $failure) {
                Log::warning("Artifact server cleanup failed before server destruction for agent {$agent->id}: {$failure->getMessage()}");
            }
        }

        $failures = $requireServerCleanup
            ? [...$serverFailures, ...$dnsFailures]
            : $dnsFailures;

        if ($failures !== []) {
            throw new RuntimeException(
                'One or more artifact cleanup operations failed: '.implode('; ', array_map(
                    static fn (Throwable $failure): string => $failure->getMessage(),
                    $failures,
                )),
                previous: $failures[0],
            );
        }

        $agent->forceFill(['artifact_cleanup_required' => false])->save();
    }

    private function publicUrl(Agent $agent, AgentArtifact $artifact): string
    {
        $subdomain = $agent->artifactSubdomain();

        if (! $subdomain) {
            throw new RuntimeException('Artifact publishing is not configured.');
        }

        $url = "https://{$subdomain}/{$artifact->path_slug}/";

        if ($artifact->isGated() && $artifact->access_token) {
            $url .= "?token={$artifact->access_token}";
        }

        return $url;
    }

    private function assertPublishingIsSupported(Agent $agent): void
    {
        if ($agent->status !== AgentStatus::Active) {
            throw new RuntimeException('Artifact publishing requires an active agent.');
        }

        if ($agent->harness_type !== HarnessType::OpenClaw) {
            throw new RuntimeException('Artifact publishing requires an OpenClaw agent.');
        }

        if (! $agent->server || $agent->server->isDocker()) {
            throw new RuntimeException('Artifact publishing requires a provisioned remote server.');
        }

        if ($agent->server->status !== ServerStatus::Running) {
            throw new RuntimeException('Artifact publishing requires a running server.');
        }

        if (! $this->isConfigured()) {
            throw new RuntimeException('Artifact publishing is not configured.');
        }
    }

    public function isConfigured(): bool
    {
        return $this->dns->isConfigured() && (bool) config('cloudflare.artifact_domain');
    }

    private function assertWithinQuota(Agent $agent, ?AgentArtifact $existing, ArtifactType $type): void
    {
        if (! $existing && $agent->artifacts()->count() >= (int) config('artifacts.max_per_agent')) {
            throw ValidationException::withMessages([
                'name' => 'This agent has reached its published artifact limit.',
            ]);
        }

        $addsApp = $type === ArtifactType::App && $existing?->type !== ArtifactType::App;

        if ($addsApp && $agent->artifacts()->where('type', ArtifactType::App)->count() >= (int) config('artifacts.max_apps_per_agent')) {
            throw ValidationException::withMessages([
                'type' => 'This agent has reached its running app limit.',
            ]);
        }
    }

    private function restorePreviousRevision(
        AgentArtifact $artifact,
        ?AgentArtifact $previous,
        Throwable $failure,
    ): void {
        if (! $previous) {
            $artifact->update([
                'status' => 'error',
                'error_message' => $failure->getMessage(),
            ]);

            return;
        }

        $artifact->forceFill([
            'name' => $previous->name,
            'type' => $previous->type,
            'source_dir' => $previous->source_dir,
            'start_command' => $previous->start_command,
            'port' => $previous->port,
            'deployment_key' => $previous->deployment_key,
            'visibility' => $previous->visibility,
            'access_token' => $previous->access_token,
            'status' => $previous->status,
            'error_message' => $previous->error_message,
            'public_url' => $previous->public_url,
            'last_published_at' => $previous->last_published_at,
        ])->save();
    }

    private function removeDeployment(Agent $agent, AgentArtifact $artifact): void
    {
        if ($artifact->type === ArtifactType::App) {
            $this->apps->remove($agent, $artifact);

            return;
        }

        $this->static->remove($agent, $artifact);
    }

    private function removeAllDeployments(Agent $agent, AgentArtifact $artifact): void
    {
        $this->apps->removeArtifact($agent, $artifact);
        $this->static->removeArtifact($agent, $artifact);
    }

    private function removeSupersededDeployments(Agent $agent, AgentArtifact $artifact): void
    {
        if ($artifact->type === ArtifactType::App) {
            $this->static->removeArtifact($agent, $artifact);
            $this->apps->removeStaleRevisions($agent, $artifact);

            return;
        }

        $this->apps->removeArtifact($agent, $artifact);
        $this->static->removeStaleRevisions($agent, $artifact);
    }

    private function removeDeploymentBestEffort(Agent $agent, AgentArtifact $artifact): void
    {
        try {
            $this->removeDeployment($agent, $artifact);
        } catch (Throwable $cleanupFailure) {
            Log::warning("Failed artifact deployment cleanup for {$artifact->id}: {$cleanupFailure->getMessage()}");
        }

        if ($agent->artifacts()->where('status', 'live')->doesntExist()) {
            try {
                $this->dns->removeAgentRecord($agent);
            } catch (Throwable $cleanupFailure) {
                Log::warning("Failed artifact DNS rollback for agent {$agent->id}: {$cleanupFailure->getMessage()}");
            }
        }
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function withServerLock(Agent $agent, Closure $callback): mixed
    {
        if (! $agent->server_id) {
            return $callback();
        }

        return Cache::lock(
            "artifact-operations:server:{$agent->server_id}",
            (int) config('artifacts.lock_seconds'),
        )->block((int) config('artifacts.lock_wait_seconds'), $callback);
    }

    private function assertSafePath(string $value, string $field, string $pattern): void
    {
        if (! preg_match($pattern, $value)) {
            throw new InvalidArgumentException("The {$field} field contains an unsafe path.");
        }
    }
}
