<?php

namespace App\Http\Requests\Governance;

use App\Enums\TaskPriority;
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
            'status' => ['sometimes', 'string', Rule::in(['backlog', 'todo', 'in_progress', 'blocked', 'done', 'cancelled', 'failed'])],
            'priority' => ['sometimes', Rule::enum(TaskPriority::class)],
            'agent_id' => ['sometimes', 'string', Rule::exists('agents', 'id')],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:50'],
        ];
    }
}
