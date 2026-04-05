<?php

namespace App\Http\Requests\Governance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateReportingRequest extends FormRequest
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
        return [
            'reports_to' => ['nullable', 'string', Rule::exists('agents', 'id')],
            'org_title' => ['nullable', 'string', 'max:255'],
            'capabilities' => ['nullable', 'string', 'max:2000'],
            'delegation_enabled' => ['sometimes', 'boolean'],
        ];
    }
}
