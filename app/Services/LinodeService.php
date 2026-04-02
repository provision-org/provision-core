<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class LinodeService
{
    private PendingRequest $http;

    public function __construct(?string $apiToken = null)
    {
        $this->http = Http::baseUrl('https://api.linode.com/v4')
            ->withToken($apiToken ?? config('cloud.linode.api_token'))
            ->acceptJson();
    }

    /**
     * @return array<string, mixed>
     */
    public function createInstance(string $label, string $type, string $image, string $region, string $userData, ?int $firewallId = null): array
    {
        $rootPassword = Str::random(32);

        $payload = [
            'label' => $label,
            'type' => $type,
            'image' => $image,
            'region' => $region,
            'tags' => ['provision'],
            'root_pass' => $rootPassword,
            'authorized_keys' => array_filter([self::sshPublicKey()]),
            'booted' => true,
            'metadata' => [
                'user_data' => base64_encode($userData),
            ],
        ];

        if ($firewallId) {
            $payload['firewall_id'] = $firewallId;
        }

        $response = $this->http->post('/linode/instances', $payload);
        $response->throw();

        $data = $response->json();
        $data['_root_password'] = $rootPassword;

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function createVolume(string $label, int $sizeGb, string $region): array
    {
        $response = $this->http->post('/volumes', [
            'label' => $label,
            'size' => $sizeGb,
            'region' => $region,
            'tags' => ['provision'],
        ]);

        $response->throw();

        return $response->json();
    }

    /**
     * @return array<string, mixed>
     */
    public function attachVolume(int $volumeId, int $linodeId): array
    {
        $response = $this->http->post("/volumes/{$volumeId}/attach", [
            'linode_id' => $linodeId,
        ]);

        $response->throw();

        return $response->json();
    }

    public function detachVolume(int $volumeId): void
    {
        $this->http->post("/volumes/{$volumeId}/detach")->throw();
    }

    public function updateInstanceLabel(string $linodeId, string $label): void
    {
        $this->http->put("/linode/instances/{$linodeId}", [
            'label' => $label,
        ])->throw();
    }

    public function deleteInstance(string $linodeId): void
    {
        $this->http->delete("/linode/instances/{$linodeId}")->throw();
    }

    public function deleteVolume(string $volumeId): void
    {
        $this->http->delete("/volumes/{$volumeId}")->throw();
    }

    /**
     * @return array<string, mixed>
     */
    public function getInstance(string $linodeId): array
    {
        $response = $this->http->get("/linode/instances/{$linodeId}");
        $response->throw();

        return $response->json();
    }

    /**
     * Extract public IPv4 from a Linode instance response.
     */
    public function extractIpAddress(array $instance): ?string
    {
        return $instance['ipv4'][0] ?? null;
    }

    private static function sshPublicKey(): ?string
    {
        $path = config('cloud.linode.ssh_public_key_path');

        if ($path && file_exists($path)) {
            return trim(file_get_contents($path));
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function createFirewall(string $label, int $linodeId): array
    {
        $response = $this->http->post('/networking/firewalls', [
            'label' => $label,
            'rules' => [
                'inbound_policy' => 'DROP',
                'outbound_policy' => 'ACCEPT',
                'inbound' => [
                    [
                        'protocol' => 'TCP',
                        'ports' => '22',
                        'addresses' => ['ipv4' => ['0.0.0.0/0'], 'ipv6' => ['::/0']],
                        'action' => 'ACCEPT',
                        'label' => 'allow-ssh',
                    ],
                    [
                        'protocol' => 'TCP',
                        'ports' => '80',
                        'addresses' => ['ipv4' => ['0.0.0.0/0'], 'ipv6' => ['::/0']],
                        'action' => 'ACCEPT',
                        'label' => 'allow-http',
                    ],
                    [
                        'protocol' => 'TCP',
                        'ports' => '443',
                        'addresses' => ['ipv4' => ['0.0.0.0/0'], 'ipv6' => ['::/0']],
                        'action' => 'ACCEPT',
                        'label' => 'allow-https',
                    ],
                ],
            ],
            'devices' => [
                'linodes' => [$linodeId],
            ],
        ]);

        $response->throw();

        return $response->json();
    }
}
