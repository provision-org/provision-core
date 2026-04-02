<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTaskRequest extends FormRequest
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
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'status' => ['sometimes', 'string', Rule::in(['inbox', 'up_next', 'in_progress', 'in_review', 'done'])],
            'priority' => ['sometimes', 'string', Rule::in(['none', 'low', 'medium', 'high'])],
            'agent_id' => ['nullable', 'string', 'exists:agents,id'],
            'sort_order' => ['sometimes', 'integer'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:50'],
        ];
    }
}
