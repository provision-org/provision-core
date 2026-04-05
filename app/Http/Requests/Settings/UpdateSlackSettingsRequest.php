<?php

namespace App\Http\Requests\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSlackSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'dm_policy' => ['required', 'string', Rule::in(['open', 'disabled'])],
            'group_policy' => ['required', 'string', Rule::in(['open', 'disabled'])],
            'require_mention' => ['required', 'boolean'],
            'reply_to_mode' => ['required', 'string', Rule::in(['off', 'first', 'all'])],
            'dm_session_scope' => ['required', 'string', Rule::in(['main', 'per-peer'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'dm_policy.in' => 'DM policy must be either open or disabled.',
            'group_policy.in' => 'Channel policy must be either open or disabled.',
            'reply_to_mode.in' => 'Reply threading must be off, first, or all.',
            'dm_session_scope.in' => 'DM session scope must be main or per-peer.',
        ];
    }
}
