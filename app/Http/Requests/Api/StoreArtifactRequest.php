<?php

namespace App\Http\Requests\Api;

use App\Enums\ArtifactType;
use App\Enums\ArtifactVisibility;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreArtifactRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'path_slug' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9][a-z0-9-]*$/'],
            'type' => ['nullable', Rule::enum(ArtifactType::class)],
            'source_dir' => [
                'nullable',
                'string',
                'max:120',
                'regex:/^[a-z0-9][a-z0-9._-]*(?:\/[a-z0-9][a-z0-9._-]*)*$/',
            ],
            'start_command' => ['nullable', 'string', 'max:500', 'required_if:type,app'],
            'visibility' => ['nullable', Rule::enum(ArtifactVisibility::class)],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('path_slug') && $this->filled('name')) {
            $this->merge(['path_slug' => Str::slug((string) $this->input('name'))]);
        }
    }
}
