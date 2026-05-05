<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamEmailDomain extends Model
{
    use HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'mailboxkit_domain_id',
        'name',
        'is_verified',
        'mx_verified',
        'spf_verified',
        'dkim_verified',
        'dmarc_verified',
        'dns_records',
        'verified_at',
        'last_checked_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_verified' => 'boolean',
            'mx_verified' => 'boolean',
            'spf_verified' => 'boolean',
            'dkim_verified' => 'boolean',
            'dmarc_verified' => 'boolean',
            'dns_records' => 'array',
            'verified_at' => 'datetime',
            'last_checked_at' => 'datetime',
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
