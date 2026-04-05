<?php

namespace App\Http\Requests\Governance;

use App\Enums\GoalPriority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGoalRequest extends FormRequest
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
            'parent_id' => ['nullable', 'string', Rule::exists('goals', 'id')],
            'owner_agent_id' => ['nullable', 'string', Rule::exists('agents', 'id')],
            'priority' => ['required', Rule::enum(GoalPriority::class)],
            'target_date' => ['nullable', 'date', 'after:today'],
        ];
    }
}
