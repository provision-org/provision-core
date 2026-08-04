<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class DaemonHeartbeatRequest extends FormRequest
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
            'timestamp' => ['nullable', 'date'],
            'active_runs' => ['nullable', 'array', 'max:100'],
            'active_runs.*' => ['string', 'max:255'],
            'version' => ['nullable', 'string', 'max:32'],
            'capabilities' => ['nullable', 'array', 'max:20'],
            'capabilities.*' => ['string', 'max:64'],
        ];
    }
}
