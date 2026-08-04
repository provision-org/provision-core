<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SyncOpenClawSessionsRequest extends FormRequest
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
            'sessions' => ['required', 'array', 'max:500'],
            'sessions.*' => ['required', 'array'],
            'sessions.*.agentId' => ['required', 'string', 'max:255'],
            'sessions.*.key' => ['required', 'string', 'max:255'],
            'sessions.*.kind' => ['required', 'string', Rule::in(['direct', 'group', 'global', 'unknown'])],
            'sessions.*.channel' => ['nullable', 'string', 'max:64'],
            'sessions.*.chatType' => ['nullable', 'string', 'max:64'],
            'sessions.*.label' => ['nullable', 'string', 'max:255'],
            'sessions.*.displayName' => ['nullable', 'string', 'max:255'],
            'sessions.*.derivedTitle' => ['nullable', 'string', 'max:255'],
            'sessions.*.subject' => ['nullable', 'string', 'max:255'],
            'sessions.*.lastMessagePreview' => ['nullable', 'string', 'max:500'],
            'sessions.*.updatedAt' => ['nullable', 'integer', 'min:0'],
            'sessions.*.hasActiveRun' => ['nullable', 'boolean'],
            'sessions.*.activeRunIds' => ['nullable', 'array', 'max:20'],
            'sessions.*.activeRunIds.*' => ['string', 'max:255'],
            'sessions.*.spawnedBy' => ['nullable', 'string', 'max:255'],
            'sessions.*.subagentRole' => ['nullable', 'string', 'max:64'],
        ];
    }
}
