<?php

namespace App\Http\Requests\Settings;

use App\Concerns\TeamValidationRules;
use App\Contracts\Modules\BillingProvider;
use App\Enums\CloudProvider;
use App\Enums\HarnessType;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateTeamRequest extends FormRequest
{
    use TeamValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => $this->teamNameRules(),
            'harness_type' => ['sometimes', 'string', Rule::in(array_column(HarnessType::cases(), 'value'))],
            'company_name' => ['nullable', 'string', 'max:255'],
            'company_url' => ['nullable', 'url', 'max:500'],
            'company_description' => ['nullable', 'string', 'max:2000'],
            'target_market' => ['nullable', 'string', 'max:500'],
            'cloud_provider' => [
                'sometimes',
                'nullable',
                'string',
                Rule::in(array_column(CloudProvider::cases(), 'value')),
                $this->byoCloudEligibilityRule(),
                $this->asciiAvailabilityRule(),
            ],
            'aws_key_id' => ['required_if:cloud_provider,aws', 'nullable', 'string', 'max:128'],
            'aws_secret' => ['required_if:cloud_provider,aws', 'nullable', 'string', 'max:128'],
            'aws_region' => ['nullable', 'string', 'max:32'],
            'aws_instance_profile' => ['required_if:cloud_provider,aws', 'nullable', 'string', 'max:128'],
            'aws_subnet_id' => ['nullable', 'string', 'max:64'],
            'aws_bedrock_model' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * BYO AWS is gated behind the account-level byo_cloud_enabled flag —
     * reject aws even if a non-flagged user posts it directly. Flagged
     * users choose per team: their own AWS or the managed cloud.
     */
    private function byoCloudEligibilityRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ($value === CloudProvider::Aws->value && ! $this->user()?->byo_cloud_enabled) {
                $fail('You are not eligible to bring your own AWS account.');
            }
        };
    }

    /**
     * ASCII remains an experimental direct-provisioning path until the
     * billing module and warm pool are provider-aware.
     */
    private function asciiAvailabilityRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ($value !== CloudProvider::Ascii->value) {
                return;
            }

            if (! config('cloud.ascii.api_token')) {
                $fail('ASCII Box is not configured.');
            }

            if (app()->bound(BillingProvider::class)) {
                $fail('ASCII Box is not available with managed billing yet.');
            }
        };
    }
}
