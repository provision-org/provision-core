<?php

namespace App\Http\Requests\Settings;

use App\Concerns\TeamValidationRules;
use App\Enums\CloudProvider;
use App\Enums\HarnessType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateTeamRequest extends FormRequest
{
    use TeamValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => $this->teamNameRules(),
            'harness_type' => ['required', 'string', Rule::in(array_column(HarnessType::cases(), 'value'))],
            'company_name' => ['nullable', 'string', 'max:255'],
            'company_url' => ['nullable', 'url', 'max:500'],
            'company_description' => ['nullable', 'string', 'max:2000'],
            'target_market' => ['nullable', 'string', 'max:500'],
            'cloud_provider' => ['sometimes', 'string', Rule::in(array_column(CloudProvider::cases(), 'value'))],
        ];
    }
}
