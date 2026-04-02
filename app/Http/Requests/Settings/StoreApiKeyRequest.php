<?php

namespace App\Http\Requests\Settings;

use App\Enums\LlmProvider;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreApiKeyRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'provider' => ['required', Rule::enum(LlmProvider::class)],
            'api_key' => ['required', 'string', 'min:10'],
        ];
    }
}
