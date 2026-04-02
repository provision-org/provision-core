<?php

namespace App\Http\Requests\Settings;

use App\Concerns\TeamValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateTeamRequest extends FormRequest
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
            'company_name' => ['nullable', 'string', 'max:255'],
            'company_url' => ['nullable', 'url', 'max:500'],
            'company_description' => ['nullable', 'string', 'max:2000'],
            'target_market' => ['nullable', 'string', 'max:500'],
        ];
    }
}
