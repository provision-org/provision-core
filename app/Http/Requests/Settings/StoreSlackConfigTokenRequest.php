<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class StoreSlackConfigTokenRequest extends FormRequest
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
            'access_token' => ['required', 'string', 'starts_with:xoxe.xoxp-'],
            'refresh_token' => ['required', 'string', 'starts_with:xoxe-'],
        ];
    }
}
