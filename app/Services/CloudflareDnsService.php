<?php

namespace App\Services;

use App\Models\Agent;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Manages Cloudflare DNS records for agent artifact subdomains.
 *
 * Each agent that publishes an artifact gets an A record
 * {agent.slug}.{artifact_domain} pointing at its server's IP. Records are
 * DNS-only (not proxied) so Caddy on the agent server can complete the
 * Let's Encrypt HTTP-01 challenge for on-demand TLS.
 */
class CloudflareDnsService
{
    private const BASE_URL = 'https://api.cloudflare.com/client/v4';

    public function isConfigured(): bool
    {
        return (bool) config('cloudflare.api_token')
            && (bool) config('cloudflare.zone_id')
            && (bool) config('cloudflare.artifact_domain');
    }

    /**
     * Ensure an A record exists for the agent's subdomain pointing at its
     * server. Idempotent — returns the Cloudflare record id.
     */
    public function ensureAgentRecord(Agent $agent): string
    {
        $this->assertConfigured();

        $ip = $agent->server?->ipv4_address;
        if (! $ip) {
            throw new RuntimeException("Agent {$agent->id} has no server IP to point a DNS record at.");
        }

        $name = $this->recordName($agent);

        if ($existing = $this->findRecord($name)) {
            // Repoint if the server IP changed.
            if (($existing['content'] ?? null) !== $ip) {
                $this->client()->patch("/zones/{$this->zoneId()}/dns_records/{$existing['id']}", [
                    'content' => $ip,
                ])->throw();
            }

            return $existing['id'];
        }

        $response = $this->client()->post("/zones/{$this->zoneId()}/dns_records", [
            'type' => 'A',
            'name' => $name,
            'content' => $ip,
            'ttl' => 60,
            'proxied' => false,
        ])->throw();

        return $response->json('result.id');
    }

    /**
     * Delete the agent's DNS record, if present.
     */
    public function removeAgentRecord(Agent $agent): void
    {
        if (! $this->isConfigured()) {
            return;
        }

        $record = $this->findRecord($this->recordName($agent));
        if ($record) {
            $this->client()->delete("/zones/{$this->zoneId()}/dns_records/{$record['id']}")->throw();
        }
    }

    public function recordName(Agent $agent): string
    {
        return "{$agent->slug}.".config('cloudflare.artifact_domain');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findRecord(string $name): ?array
    {
        $response = $this->client()->get("/zones/{$this->zoneId()}/dns_records", [
            'type' => 'A',
            'name' => $name,
        ])->throw();

        return $response->json('result.0');
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->withToken((string) config('cloudflare.api_token'))
            ->acceptJson()
            ->asJson();
    }

    private function zoneId(): string
    {
        return (string) config('cloudflare.zone_id');
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Cloudflare DNS is not configured (CLOUDFLARE_API_TOKEN, CLOUDFLARE_ZONE_ID, ARTIFACT_DOMAIN).');
        }
    }
}
