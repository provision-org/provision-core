<?php

namespace App\Http\Requests\Governance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReportResultRequest extends FormRequest
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
            'daemon_run_id' => ['required', 'string', 'max:255'],
            'status' => ['required', 'string', Rule::in(['done', 'in_progress', 'blocked', 'failed'])],
            'result_summary' => ['nullable', 'string', 'max:10000'],
            'tokens_input' => ['nullable', 'integer', 'min:0'],
            'tokens_output' => ['nullable', 'integer', 'min:0'],
            'model' => ['nullable', 'string', 'max:255'],
            'delegations' => ['nullable', 'array'],
            'delegations.*.agent_name' => ['required_with:delegations', 'string'],
            'delegations.*.title' => ['required_with:delegations', 'string', 'max:255'],
            'delegations.*.description' => ['nullable', 'string', 'max:5000'],
            'delegations.*.priority' => ['nullable', 'string'],
            'approval_requests' => ['nullable', 'array'],
            'approval_requests.*.type' => ['required_with:approval_requests', 'string'],
            'approval_requests.*.title' => ['required_with:approval_requests', 'string', 'max:255'],
            'approval_requests.*.payload' => ['nullable', 'array'],
            'work_products' => ['nullable', 'array'],
            'work_products.*.title' => ['required_with:work_products', 'string', 'max:255'],
            'work_products.*.file_path' => ['nullable', 'string', 'max:1000'],
            'work_products.*.url' => ['nullable', 'string', 'max:2000'],
            'work_products.*.type' => ['nullable', 'string', 'max:50'],
            'work_products.*.summary' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
