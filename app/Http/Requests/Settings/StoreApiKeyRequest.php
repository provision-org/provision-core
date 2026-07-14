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
            // Bedrock is excluded: it authenticates via the EC2 instance
            // profile, so there is no API key a user could paste.
            'provider' => ['required', Rule::enum(LlmProvider::class)->except([LlmProvider::Bedrock])],
            'api_key' => ['required', 'string', 'min:10'],
        ];
    }
}
