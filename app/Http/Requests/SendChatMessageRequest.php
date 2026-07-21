<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendChatMessageRequest extends FormRequest
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
            'content' => ['nullable', 'string', 'max:10000', 'required_without:attachments'],
            'attachments' => ['nullable', 'array', 'max:5', 'required_without:content'],
            'attachments.*' => ['file', 'max:10240'],
            'client_message_id' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'content.required_without' => 'Please enter a message or attach a file.',
            'content.max' => 'Message must be under 10,000 characters.',
            'attachments.required_without' => 'Please enter a message or attach a file.',
            'attachments.max' => 'You can attach up to 5 files.',
            'attachments.*.max' => 'Each file must be under 10MB.',
        ];
    }
}
