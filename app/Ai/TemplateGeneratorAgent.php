<?php

namespace App\Ai;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TemplateGeneratorAgent
{
    /**
     * Personalize agent template content using user profile data.
     *
     * @param  array{soul: string, system_prompt: string, user_context: string}  $staticTemplate
     * @return array{soul: string, system_prompt: string, user_context: string}
     */
    public function personalize(User $user, string $roleName, array $staticTemplate): array
    {
        $profileContext = $this->buildProfileContext($user);

        $response = Http::withToken(config('services.openai.api_key'))
            ->baseUrl('https://api.openai.com/v1')
            ->timeout(60)
            ->post('/chat/completions', [
                'model' => 'gpt-4.1-mini',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => <<<'PROMPT'
You are a template personalizer for AI agent configurations. Given a user's company profile and static agent templates, personalize the templates by:
- Replacing generic placeholders like [Company Name], [Your Product], [Target Market], etc. with actual company data
- Adapting the tone and content to match the user's industry and target market
- Keeping the overall structure and intent of each template intact
- Not inventing information that wasn't provided — only use what's in the profile

Return the personalized versions of soul, system_prompt, and user_context as JSON.
PROMPT,
                    ],
                    [
                        'role' => 'user',
                        'content' => "## User Profile\n{$profileContext}\n\n## Agent Role\n{$roleName}\n\n## Soul Template\n{$staticTemplate['soul']}\n\n## System Prompt Template\n{$staticTemplate['system_prompt']}\n\n## User Context Template\n{$staticTemplate['user_context']}",
                    ],
                ],
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => [
                        'name' => 'personalized_template',
                        'strict' => true,
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'soul' => ['type' => 'string'],
                                'system_prompt' => ['type' => 'string'],
                                'user_context' => ['type' => 'string'],
                            ],
                            'required' => ['soul', 'system_prompt', 'user_context'],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
            ]);

        $response->throw();

        $content = $response->json('choices.0.message.content');

        $parsed = json_decode($content, true);

        if (! is_array($parsed)) {
            Log::warning('TemplateGeneratorAgent: Failed to parse response', ['content' => $content]);

            throw new \RuntimeException('Failed to parse LLM response');
        }

        return [
            'soul' => $parsed['soul'] ?? $staticTemplate['soul'],
            'system_prompt' => $parsed['system_prompt'] ?? $staticTemplate['system_prompt'],
            'user_context' => $parsed['user_context'] ?? $staticTemplate['user_context'],
        ];
    }

    private function buildProfileContext(User $user): string
    {
        $lines = [];

        if ($user->company_name) {
            $lines[] = "- Company: {$user->company_name}";
        }

        if ($user->company_url) {
            $lines[] = "- Website: {$user->company_url}";
        }

        if ($user->company_description) {
            $lines[] = "- Description: {$user->company_description}";
        }

        if ($user->target_market) {
            $lines[] = "- Target Market: {$user->target_market}";
        }

        if ($user->pronouns) {
            $lines[] = "- User Pronouns: {$user->pronouns}";
        }

        return implode("\n", $lines) ?: 'No profile information available.';
    }
}
