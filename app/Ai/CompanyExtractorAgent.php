<?php

namespace App\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CompanyExtractorAgent
{
    /**
     * Extract company info from website markdown content.
     *
     * @return array{company_name: string, company_description: string, target_market: string}
     */
    public function extract(string $markdown): array
    {
        $response = Http::withToken(config('services.openai.api_key'))
            ->baseUrl('https://api.openai.com/v1')
            ->timeout(30)
            ->post('/chat/completions', [
                'model' => 'gpt-5-nano',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'Extract company information from website content. Return JSON with: company_name (the company or product name — always extractable from a website), company_description (2-3 sentences about what the company does), and target_market (who their customers are, inferred from the product/service if not stated explicitly). Always provide your best answer for every field — never return empty strings.',
                    ],
                    [
                        'role' => 'user',
                        'content' => $markdown,
                    ],
                ],
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => [
                        'name' => 'company_info',
                        'strict' => true,
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'company_name' => ['type' => 'string'],
                                'company_description' => ['type' => 'string'],
                                'target_market' => ['type' => 'string'],
                            ],
                            'required' => ['company_name', 'company_description', 'target_market'],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
            ]);

        $response->throw();

        $content = $response->json('choices.0.message.content');

        $parsed = json_decode($content, true);

        if (! is_array($parsed)) {
            Log::warning('CompanyExtractorAgent: Failed to parse response', ['content' => $content]);

            return ['company_name' => '', 'company_description' => '', 'target_market' => ''];
        }

        return [
            'company_name' => $parsed['company_name'] ?? '',
            'company_description' => $parsed['company_description'] ?? '',
            'target_market' => $parsed['target_market'] ?? '',
        ];
    }
}
