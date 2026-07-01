<?php

namespace App\Services;

use App\Enums\ArtifactType;
use App\Enums\ArtifactVisibility;
use App\Models\Agent;
use App\Models\AgentArtifact;
use Illuminate\Support\Str;
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
    ) {}

    /**
     * Publish (or re-publish) an artifact for an agent.
     *
     * @param  array<string, mixed>  $data
     */
    public function publish(Agent $agent, array $data): AgentArtifact
    {
        $pathSlug = $data['path_slug'];

        $artifact = $agent->artifacts()->updateOrCreate(
            ['path_slug' => $pathSlug],
            [
                'team_id' => $agent->team_id,
                'name' => $data['name'],
                'type' => $data['type'] ?? ArtifactType::Static,
                'source_dir' => $data['source_dir'] ?? $pathSlug,
                'start_command' => $data['start_command'] ?? null,
                'port' => $data['port'] ?? null,
                'visibility' => $data['visibility'] ?? ArtifactVisibility::Public,
                'status' => 'pending',
            ],
        );

        // Gated artifacts need a shared-link token before we compute the URL.
        if ($artifact->isGated() && ! $artifact->access_token) {
            $artifact->access_token = Str::random(40);
        }

        // Mark live first so the Caddy sync (which reads live artifacts) includes it.
        $artifact->fill([
            'status' => 'live',
            'public_url' => $this->publicUrl($agent, $artifact),
            'last_published_at' => now(),
            'error_message' => null,
        ])->save();

        try {
            // App artifacts run a process on an allocated port that Caddy
            // reverse-proxies to; set that up before syncing Caddy.
            if ($artifact->type === ArtifactType::App) {
                if (! $artifact->port && $agent->server) {
                    $artifact->update(['port' => $this->apps->allocatePort($agent->server)]);
                }
                $this->apps->deploy($agent, $artifact);
            }

            if ($this->dns->isConfigured()) {
                $this->dns->ensureAgentRecord($agent);
            }
            $this->caddy->syncAgent($agent);
        } catch (Throwable $e) {
            $artifact->update(['status' => 'error', 'error_message' => $e->getMessage()]);
            throw $e;
        }

        return $artifact->fresh();
    }

    /**
     * Unpublish an artifact: remove it, re-sync Caddy, and drop the agent's DNS
     * record once it has no live artifacts left.
     */
    public function unpublish(AgentArtifact $artifact): void
    {
        $agent = $artifact->agent;

        if ($artifact->type === ArtifactType::App) {
            $this->apps->remove($agent, $artifact);
        }

        $artifact->delete();

        $this->caddy->syncAgent($agent);

        if ($this->dns->isConfigured()
            && ! $agent->artifacts()->where('status', 'live')->exists()) {
            $this->dns->removeAgentRecord($agent);
        }
    }

    private function publicUrl(Agent $agent, AgentArtifact $artifact): string
    {
        $url = "https://{$agent->artifactSubdomain()}/{$artifact->path_slug}/";

        if ($artifact->isGated() && $artifact->access_token) {
            $url .= "?token={$artifact->access_token}";
        }

        return $url;
    }
}
