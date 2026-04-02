<?php

namespace App\Http\Requests\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreEnvVarRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'key' => ['required', 'string', 'regex:/^[A-Z][A-Z0-9_]*$/', 'max:255', 'not_in:MAILBOXKIT_API_KEY'],
            'value' => ['required', 'string'],
            'is_secret' => ['nullable', 'boolean'],
        ];
    }
}
