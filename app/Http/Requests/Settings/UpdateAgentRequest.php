<?php

namespace App\Http\Requests\Settings;

use App\Enums\CloudProvider;
use App\Http\Controllers\AgentController;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAgentRequest extends FormRequest
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
        $team = $this->user()->currentTeam;
        $allowedModels = AgentController::allowedModelIds($team);

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'job_description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'model_primary' => [
                'nullable', 'string',
                function (string $attribute, mixed $value, \Closure $fail) use ($team, $allowedModels): void {
                    // Bedrock ids ("bedrock:<raw-aws-id>") are trusted from the
                    // verified wizard selection; managed models stay enum-checked.
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
            'soul' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'tools_config' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'user_context' => ['sometimes', 'nullable', 'string', 'max:10000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'model_primary.in' => 'The selected model is not available. Please configure an API key for that provider.',
        ];
    }
}
