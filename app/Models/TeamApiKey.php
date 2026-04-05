<?php

namespace App\Models;

use App\Enums\LlmProvider;
use Database\Factories\TeamApiKeyFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamApiKey extends Model
{
    /** @use HasFactory<TeamApiKeyFactory> */
    use HasFactory, HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'provider_type',
        'provider',
        'api_key',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Cast provider to LlmProvider enum for LLM keys, or return the raw string for cloud keys.
     *
     * @return Attribute<LlmProvider|string, LlmProvider|string>
     */
    protected function provider(): Attribute
    {
        return Attribute::make(
            get: function (string $value) {
                $enum = LlmProvider::tryFrom($value);

                return $enum ?? $value;
            },
            set: function (LlmProvider|string $value) {
                return $value instanceof LlmProvider ? $value->value : $value;
            },
        );
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function maskedKey(): string
    {
        $decrypted = $this->api_key;

        if (strlen($decrypted) <= 12) {
            return str_repeat('•', strlen($decrypted));
        }

        return substr($decrypted, 0, 8).'...'.substr($decrypted, -4);
    }
}
