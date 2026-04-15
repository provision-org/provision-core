<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManagedApiKey extends Model
{
    use HasFactory, HasUlids;

    protected $table = 'managed_api_keys';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'openrouter_key_hash',
        'api_key',
        'name',
        'credit_limit_cents',
        'last_synced_usage_cents',
        'last_synced_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'credit_limit_cents' => 'integer',
            'last_synced_usage_cents' => 'integer',
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
