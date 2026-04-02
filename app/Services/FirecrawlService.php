<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class FirecrawlService
{
    /**
     * Scrape a URL and return markdown content with metadata.
     *
     * @return array{markdown: string, metadata: array<string, mixed>}
     */
    public function scrape(string $url): array
    {
        $response = $this->client()->post('/v1/scrape', [
            'url' => $url,
            'formats' => ['markdown'],
        ]);

        $response->throw();

        $data = $response->json('data');

        return [
            'markdown' => $data['markdown'] ?? '',
            'metadata' => $data['metadata'] ?? [],
        ];
    }

    private function client(): PendingRequest
    {
        return Http::withToken(config('services.firecrawl.api_key'))
            ->baseUrl(config('services.firecrawl.base_url'))
            ->timeout(30);
    }
}
