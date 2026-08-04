<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IngestOpenClawChatEventsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->get('daemon_server') !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'events' => ['required', 'array', 'max:100'],
            'events.*' => ['required', 'array'],
            'events.*.event' => ['required', 'string', Rule::in([
                'chat',
                'session.message',
                'session.tool',
                'sessions.changed',
            ])],
            'events.*.agent_id' => ['required', 'string', 'max:255'],
            'events.*.session_key' => ['required', 'string', 'max:255'],
            'events.*.run_id' => ['nullable', 'string', 'max:255'],
            'events.*.sequence' => ['nullable', 'integer', 'min:0'],
            'events.*.state' => ['nullable', 'string', Rule::in(['delta', 'final', 'aborted', 'error'])],
            'events.*.delta' => ['nullable', 'string', 'max:50000'],
            'events.*.cumulative' => ['nullable', 'string', 'max:200000'],
            'events.*.replace' => ['nullable', 'boolean'],
            'events.*.role' => ['nullable', 'string', Rule::in(['user', 'assistant'])],
            'events.*.idempotency_key' => ['nullable', 'string', 'max:255'],
            'events.*.message_id' => ['nullable', 'string', 'max:255'],
            'events.*.message_sequence' => ['nullable', 'integer', 'min:0'],
            'events.*.tool' => ['nullable', 'string', 'max:128'],
            'events.*.phase' => ['nullable', 'string', 'max:64'],
            'events.*.label' => ['nullable', 'string', 'max:255'],
            'events.*.error_kind' => ['nullable', 'string', Rule::in([
                'refusal',
                'timeout',
                'rate_limit',
                'context_length',
                'unknown',
            ])],
            'events.*.has_active_run' => ['nullable', 'boolean'],
        ];
    }
}
