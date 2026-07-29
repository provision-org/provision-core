<?php

namespace App\Services;

use App\Models\Team;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

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
        $response = $this->http
            ->retry(
                [100, 500],
                when: fn (Throwable $e): bool => $this->shouldRetryDelete($e),
                throw: false,
            )
            ->delete("/keys/{$hash}");

        if ($response->status() === 404) {
            return;
        }

        $response->throw();
    }

    private function shouldRetryDelete(Throwable $e): bool
    {
        if ($e instanceof ConnectionException) {
            return true;
        }

        if (! $e instanceof RequestException) {
            return false;
        }

        $status = $e->response?->status();

        return in_array($status, [408, 429], true) || ($status !== null && $status >= 500);
    }
}
