<?php

namespace App\Services;

use App\Models\Team;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class OpenRouterKeyService
{
    private PendingRequest $http;

    public function __construct()
    {
        $this->http = Http::baseUrl('https://openrouter.ai/api/v1')
            ->withToken(config('services.openrouter.provisioning_api_key'))
            ->acceptJson();
    }

    /**
     * @return array{hash: string, key: string}
     */
    public function createKey(Team $team): array
    {
        $response = $this->http->post('/keys', [
            'name' => "Provision-{$team->id}",
        ]);

        $response->throw();

        return [
            'hash' => $response->json('data.hash'),
            'key' => $response->json('key'),
        ];
    }

    public function deleteKey(string $hash): void
    {
        $this->http->delete("/keys/{$hash}")->throw();
    }
}
