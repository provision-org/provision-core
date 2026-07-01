<?php

namespace App\Services;

use App\Enums\ArtifactType;
use App\Enums\ArtifactVisibility;
use App\Models\Agent;
use App\Models\AgentArtifact;
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

        // Mark live first so the Caddy sync (which reads live artifacts) includes it.
        $artifact->update([
            'status' => 'live',
            'public_url' => $this->publicUrl($agent, $artifact),
            'last_published_at' => now(),
            'error_message' => null,
        ]);

        try {
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
        $artifact->delete();

        $this->caddy->syncAgent($agent);

        if ($this->dns->isConfigured()
            && ! $agent->artifacts()->where('status', 'live')->exists()) {
            $this->dns->removeAgentRecord($agent);
        }
    }

    private function publicUrl(Agent $agent, AgentArtifact $artifact): string
    {
        return "https://{$agent->artifactSubdomain()}/{$artifact->path_slug}/";
    }
}
