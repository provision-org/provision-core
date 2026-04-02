<?php

namespace App\Http\Requests\Settings;

use App\Http\Controllers\AgentController;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
        $allowedModels = AgentController::allowedModelIds($this->user()->currentTeam);

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'job_description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'model_primary' => ['nullable', 'string', Rule::in($allowedModels)],
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
