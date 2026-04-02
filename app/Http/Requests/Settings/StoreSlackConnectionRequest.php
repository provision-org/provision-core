<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class StoreSlackConnectionRequest extends FormRequest
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
            'bot_token' => ['required', 'string', 'starts_with:xoxb-'],
            'app_token' => ['required', 'string', 'starts_with:xapp-'],
            'allowed_channels' => ['nullable', 'array'],
            'allowed_channels.*' => ['string'],
        ];
    }
}
