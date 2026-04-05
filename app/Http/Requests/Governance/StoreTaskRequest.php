<?php

namespace App\Http\Requests\Governance;

use App\Enums\TaskPriority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTaskRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'agent_id' => ['required', 'string', Rule::exists('agents', 'id')],
            'goal_id' => ['nullable', 'string', Rule::exists('goals', 'id')],
            'parent_task_id' => ['nullable', 'string', Rule::exists('tasks', 'id')],
            'priority' => ['required', Rule::enum(TaskPriority::class)],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:50'],
        ];
    }
}
