<?php

namespace App\Http\Requests\Governance;

use App\Enums\GoalPriority;
use App\Enums\GoalStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateGoalRequest extends FormRequest
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
            'status' => ['sometimes', Rule::enum(GoalStatus::class)],
            'priority' => ['sometimes', Rule::enum(GoalPriority::class)],
            'target_date' => ['nullable', 'date'],
            'owner_agent_id' => ['nullable', 'string', Rule::exists('agents', 'id')],
        ];
    }
}
