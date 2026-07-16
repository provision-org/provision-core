<?php

namespace App\Http\Requests\Settings;

use App\Contracts\Modules\AgentEmailProvider;
use App\Enums\AgentMode;
use App\Enums\AgentRole;
use App\Enums\CloudProvider;
use App\Enums\ModelTier;
use App\Http\Controllers\AgentController;
use App\Models\AgentEmailConnection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateAgentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize tool URLs before validation — users routinely paste bare
     * hostnames ("mixpanel.com") and shouldn't be punished with a hard error.
     */
    protected function prepareForValidation(): void
    {
        $tools = $this->input('tools');
        if (! is_array($tools)) {
            return;
        }

        foreach ($tools as $i => $tool) {
            if (! is_array($tool)) {
                continue;
            }
            $url = isset($tool['url']) && is_string($tool['url']) ? trim($tool['url']) : '';
            if ($url === '') {
                $tools[$i]['url'] = null;

                continue;
            }
            if (! preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
                $url = 'https://'.ltrim($url, '/');
            }
            $tools[$i]['url'] = $url;
        }

        $this->merge(['tools' => $tools]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $team = $this->user()->currentTeam;
        $allowedModels = AgentController::allowedModelIds($team);
        $allowedDomains = $this->allowedEmailDomains($team);
        $emailDomain = $this->resolveChosenDomain($team, $allowedDomains);

        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('agents', 'name')->where('team_id', $team->id),
            ],
            'email_domain' => [
                'nullable', 'string',
                Rule::in(array_column($allowedDomains, 'name')),
            ],
            'email_prefix' => [
                'nullable', 'string', 'max:100', 'regex:/^[a-z0-9]([a-z0-9._-]*[a-z0-9])?$/i',
                function (string $attribute, mixed $value, \Closure $fail) use ($emailDomain): void {
                    if (! $value) {
                        return;
                    }
                    $fullEmail = strtolower($value).'@'.$emailDomain;
                    if (AgentEmailConnection::where('email_address', $fullEmail)->exists()) {
                        $fail('This email address is already taken.');
                    }
                },
            ],
            'role' => ['required', Rule::enum(AgentRole::class)],
            'job_description' => ['nullable', 'string', 'max:5000'],
            'model_tier' => [
                'nullable', Rule::enum(ModelTier::class),
                function (string $attribute, mixed $value, \Closure $fail) use ($team): void {
                    if ($value === ModelTier::Bedrock->value && $team->cloudProvider() !== CloudProvider::Aws) {
                        $fail('The Bedrock tier is only available for teams running in their own AWS account.');
                    }
                },
            ],
            'model_primary' => [
                'nullable', 'string',
                function (string $attribute, mixed $value, \Closure $fail) use ($team, $allowedModels): void {
                    // Customer-selected Bedrock models ("bedrock:<raw-aws-id>")
                    // can't be enumerated without an AWS call per request, so we
                    // trust the wizard's verified selection here — the raw id is
                    // re-checked at save (verify endpoint) and again at deploy.
                    if (is_string($value) && str_starts_with($value, 'bedrock:')) {
                        if ($team->cloudProvider() !== CloudProvider::Aws) {
                            $fail('Bedrock models are only available for teams running in their own AWS account.');
                        }

                        return;
                    }

                    if ($value !== null && ! in_array($value, $allowedModels, true)) {
                        $fail('The selected model is not available. Please configure an API key for that provider.');
                    }
                },
            ],
            'model_fallbacks' => ['nullable', 'array'],
            'model_fallbacks.*' => ['string', 'max:255'],
            'system_prompt' => ['nullable', 'string', 'max:10000'],
            'identity' => ['nullable', 'string', 'max:5000'],
            'soul' => ['nullable', 'string', 'max:10000'],
            'tools_config' => ['nullable', 'string', 'max:10000'],
            'user_context' => ['nullable', 'string', 'max:10000'],
            'emoji' => ['nullable', 'string', 'max:10'],
            'personality' => ['nullable', 'string', 'max:100'],
            'communication_style' => ['nullable', 'string', 'max:100'],
            'backstory' => ['nullable', 'string', 'max:2000'],
            'agent_mode' => ['nullable', Rule::enum(AgentMode::class)],
            'org_title' => ['nullable', 'string', 'max:100'],
            'reports_to' => ['nullable', 'string', 'exists:agents,id'],
            'tools' => ['array', 'max:20'],
            'tools.*.name' => ['required', 'string', 'max:100'],
            'tools.*.url' => ['nullable', 'string', 'url', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'model_primary.in' => 'The selected model is not available. Please configure an API key for that provider.',
            'email_prefix.regex' => 'Email prefix must contain only letters, numbers, dots, hyphens, and underscores.',
            'email_domain.in' => 'That email domain is not available for your team.',
            'tools.*.url.url' => 'One of the tool URLs looks invalid. Please use a full domain like "mixpanel.com" or "https://mixpanel.com".',
        ];
    }

    /**
     * The email domains this team may choose from — from the email module if
     * installed, otherwise just the platform default.
     *
     * @return list<array{name: string, is_default: bool, is_verified: bool}>
     */
    private function allowedEmailDomains(mixed $team): array
    {
        if (app()->bound(AgentEmailProvider::class)) {
            return app(AgentEmailProvider::class)->availableDomains($team);
        }

        return [[
            'name' => (string) config('mailboxkit.email_domain'),
            'is_default' => true,
            'is_verified' => true,
        ]];
    }

    /**
     * The domain the submitted prefix will land on: the chosen one when valid
     * and verified, otherwise the team's active domain.
     *
     * @param  list<array{name: string, is_default: bool, is_verified: bool}>  $allowedDomains
     */
    private function resolveChosenDomain(mixed $team, array $allowedDomains): string
    {
        $requested = strtolower(trim((string) $this->input('email_domain')));

        foreach ($allowedDomains as $domain) {
            if ($domain['name'] === $requested && $domain['is_verified']) {
                return $requested;
            }
        }

        return method_exists($team, 'activeEmailDomain')
            ? ($team->activeEmailDomain() ?: config('mailboxkit.email_domain'))
            : config('mailboxkit.email_domain');
    }
}
