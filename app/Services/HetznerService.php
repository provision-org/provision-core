<?php

namespace App\Services;

use App\Models\Team;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class HetznerService
{
    private PendingRequest $http;

    public function __construct(?string $apiToken = null)
    {
        $this->http = Http::baseUrl('https://api.hetzner.cloud/v1')
            ->withToken($apiToken ?? config('cloud.hetzner.api_token'))
            ->acceptJson();
    }

    /**
     * @param  list<int>  $volumeIds
     * @return array<string, mixed>
     */
    public function createServer(Team $team, string $cloudInitScript, array $volumeIds = [], ?string $serverType = null): array
    {
        $payload = [
            'name' => "provision-{$team->id}-".now()->timestamp,
            'server_type' => $serverType ?? 'cpx21',
            'image' => config('cloud.hetzner.default_image'),
            'location' => 'ash',
            'ssh_keys' => array_filter([config('cloud.hetzner.ssh_key_id')]),
            'user_data' => $cloudInitScript,
        ];

        if ($volumeIds !== []) {
            $payload['volumes'] = $volumeIds;
        }

        $response = $this->http->post('/servers', $payload);

        $response->throw();

        return $response->json();
    }

    /**
     * @return array<string, mixed>
     */
    public function createVolume(string $name, int $sizeGb): array
    {
        $response = $this->http->post('/volumes', [
            'name' => $name,
            'size' => $sizeGb,
            'location' => 'ash',
            'format' => 'ext4',
        ]);

        $response->throw();

        return $response->json();
    }

    /**
     * @return array<string, mixed>
     */
    public function attachVolume(string $volumeId, string $serverId): array
    {
        $response = $this->http->post("/volumes/{$volumeId}/actions/attach", [
            'server' => (int) $serverId,
        ]);

        $response->throw();

        return $response->json();
    }

    /**
     * @return array<string, mixed>
     */
    public function detachVolume(string $volumeId): array
    {
        $response = $this->http->post("/volumes/{$volumeId}/actions/detach");

        $response->throw();

        return $response->json();
    }

    public function deleteVolume(string $volumeId): void
    {
        $this->http->delete("/volumes/{$volumeId}")->throw();
    }

    public function updateServerName(string $hetznerServerId, string $name): void
    {
        $this->http->put("/servers/{$hetznerServerId}", [
            'name' => $name,
        ])->throw();
    }

    public function deleteServer(string $hetznerServerId): void
    {
        $this->http->delete("/servers/{$hetznerServerId}")->throw();
    }

    /**
     * @return array<string, mixed>
     */
    public function getServer(string $hetznerServerId): array
    {
        $response = $this->http->get("/servers/{$hetznerServerId}");
        $response->throw();

        return $response->json();
    }

    /**
     * @return array<string, mixed>
     */
    public function listServers(): array
    {
        $response = $this->http->get('/servers');
        $response->throw();

        return $response->json();
    }

    /**
     * @return array<string, mixed>
     */
    public function listVolumes(): array
    {
        $response = $this->http->get('/volumes');
        $response->throw();

        return $response->json();
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetrics(string $hetznerServerId): array
    {
        $response = $this->http->get("/servers/{$hetznerServerId}/metrics", [
            'type' => 'cpu,disk,network',
            'start' => now()->subHour()->toIso8601String(),
            'end' => now()->toIso8601String(),
        ]);

        $response->throw();

        return $response->json();
    }
}
