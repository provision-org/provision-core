<?php

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class ScrapeWebsite implements Tool
{
    public function description(): Stringable|string
    {
        return 'Scrape a website URL to extract information about a company, their products, services, and target market.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'url' => $schema->string()
                ->description('The website URL to scrape')
                ->required(),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $url = $request->get('url');

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.config('services.firecrawl.api_key'),
            ])->timeout(30)->post('https://api.firecrawl.dev/v1/scrape', [
                'url' => $url,
                'formats' => ['markdown'],
                'onlyMainContent' => true,
            ]);

            if (! $response->successful()) {
                return "Failed to scrape website: HTTP {$response->status()}";
            }

            $data = $response->json();
            $markdown = $data['data']['markdown'] ?? '';

            // Truncate to avoid token limits
            if (strlen($markdown) > 4000) {
                $markdown = substr($markdown, 0, 4000)."\n\n[Content truncated...]";
            }

            return $markdown ?: 'No content could be extracted from this URL.';
        } catch (\Throwable $e) {
            return "Failed to scrape website: {$e->getMessage()}";
        }
    }
}
