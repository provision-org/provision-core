<?php

namespace App\Http\Requests\Settings;

use App\Enums\AgentRole;
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
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $allowedModels = AgentController::allowedModelIds($this->user()->currentTeam);
        $emailDomain = config('mailboxkit.email_domain');

        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('agents', 'name')->where('team_id', $this->user()->currentTeam->id),
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
            'model_tier' => ['nullable', 'string', Rule::in(['efficient', 'powerful'])],
            'model_primary' => ['nullable', 'string', Rule::in($allowedModels)],
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
        ];
    }
}
