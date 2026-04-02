<?php

namespace App\Services;

use App\Models\Team;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class DigitalOceanService
{
    private PendingRequest $http;

    public function __construct(?string $apiToken = null)
    {
        $this->http = Http::baseUrl('https://api.digitalocean.com/v2')
            ->withToken($apiToken ?? config('cloud.digitalocean.api_token'))
            ->acceptJson();
    }

    /**
     * @param  list<string>  $volumeIds
     * @return array<string, mixed>
     */
    public function createDroplet(?Team $team, string $cloudInitScript, array $volumeIds = [], ?string $size = null, ?string $region = null, ?string $hostname = null): array
    {
        $payload = [
            'name' => $hostname ?? "provision-{$team->id}-".now()->timestamp,
            'size' => $size ?? 's-2vcpu-4gb',
            'image' => config('cloud.digitalocean.default_image'),
            'region' => $region ?? 'nyc1',
            'ssh_keys' => array_filter([config('cloud.digitalocean.ssh_key_id')]),
            'user_data' => $cloudInitScript,
        ];

        if ($volumeIds !== []) {
            $payload['volumes'] = $volumeIds;
        }

        $response = $this->http->post('/droplets', $payload);
        $response->throw();

        return $response->json();
    }

    /**
     * @return array<string, mixed>
     */
    public function createVolume(string $name, int $sizeGb, ?string $region = null): array
    {
        $response = $this->http->post('/volumes', [
            'name' => $name,
            'size_gigabytes' => $sizeGb,
            'region' => $region ?? 'nyc1',
            'filesystem_type' => 'ext4',
        ]);

        $response->throw();

        return $response->json();
    }

    /**
     * @return array<string, mixed>
     */
    public function attachVolume(string $volumeId, string $dropletId): array
    {
        $response = $this->http->post("/volumes/{$volumeId}/actions", [
            'type' => 'attach',
            'droplet_id' => (int) $dropletId,
        ]);

        $response->throw();

        return $response->json();
    }

    /**
     * @return array<string, mixed>
     */
    public function detachVolume(string $volumeId, string $dropletId): array
    {
        $response = $this->http->post("/volumes/{$volumeId}/actions", [
            'type' => 'detach',
            'droplet_id' => (int) $dropletId,
        ]);

        $response->throw();

        return $response->json();
    }

    public function renameDroplet(string $dropletId, string $name): void
    {
        $this->http->post("/droplets/{$dropletId}/actions", [
            'type' => 'rename',
            'name' => $name,
        ])->throw();
    }

    public function deleteDroplet(string $dropletId): void
    {
        $this->http->delete("/droplets/{$dropletId}")->throw();
    }

    public function deleteVolume(string $volumeId): void
    {
        $this->http->delete("/volumes/{$volumeId}")->throw();
    }

    /**
     * @return array<string, mixed>
     */
    public function getDroplet(string $dropletId): array
    {
        $response = $this->http->get("/droplets/{$dropletId}");
        $response->throw();

        return $response->json();
    }

    /**
     * Extract public IPv4 from a droplet response.
     */
    public function extractIpAddress(array $droplet): ?string
    {
        $networks = $droplet['networks']['v4'] ?? [];

        foreach ($networks as $network) {
            if ($network['type'] === 'public') {
                return $network['ip_address'];
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function createFirewall(string $name, int $dropletId): array
    {
        $response = $this->http->post('/firewalls', [
            'name' => $name,
            'droplet_ids' => [$dropletId],
            'inbound_rules' => [
                ['protocol' => 'tcp', 'ports' => '22', 'sources' => ['addresses' => ['0.0.0.0/0', '::/0']]],
                ['protocol' => 'tcp', 'ports' => '80', 'sources' => ['addresses' => ['0.0.0.0/0', '::/0']]],
                ['protocol' => 'tcp', 'ports' => '443', 'sources' => ['addresses' => ['0.0.0.0/0', '::/0']]],
            ],
            'outbound_rules' => [
                ['protocol' => 'tcp', 'ports' => 'all', 'destinations' => ['addresses' => ['0.0.0.0/0', '::/0']]],
                ['protocol' => 'udp', 'ports' => 'all', 'destinations' => ['addresses' => ['0.0.0.0/0', '::/0']]],
                ['protocol' => 'icmp', 'ports' => '0', 'destinations' => ['addresses' => ['0.0.0.0/0', '::/0']]],
            ],
        ]);

        $response->throw();

        return $response->json();
    }
}
