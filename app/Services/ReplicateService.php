<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ReplicateService
{
    private PendingRequest $http;

    public function __construct()
    {
        $this->http = Http::baseUrl('https://api.replicate.com/v1')
            ->withToken(config('replicate.api_token'))
            ->acceptJson()
            ->timeout(120);
    }

    /**
     * Generate an image using the configured avatar model.
     *
     * @return string The output image URL
     */
    public function generateAvatar(string $prompt): string
    {
        $model = config('replicate.avatar_model');

        $response = $this->http
            ->withHeaders(['Prefer' => 'wait'])
            ->post("/models/{$model}/predictions", [
                'input' => [
                    'prompt' => $prompt,
                ],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException("Replicate API error: {$response->status()} {$response->body()}");
        }

        $data = $response->json();

        if (($data['status'] ?? '') !== 'succeeded' || empty($data['output'])) {
            throw new RuntimeException('Replicate prediction failed: '.($data['error'] ?? 'unknown error'));
        }

        return $data['output'];
    }
}
