<?php

namespace App\Models;

use Database\Factories\MobilePairingHandoffFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MobilePairingHandoff extends Model
{
    /** @use HasFactory<MobilePairingHandoffFactory> */
    use HasFactory, HasUlids;

    protected $hidden = [
        'token_hash',
        'failure_code',
    ];

    protected $fillable = [
        'team_id',
        'agent_id',
        'server_id',
        'created_by_user_id',
        'token_hash',
        'expires_at',
        'consumed_at',
        'completed_at',
        'revoked_at',
        'failed_at',
        'failure_code',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'completed_at' => 'datetime',
            'revoked_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<Agent, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function status(): string
    {
        if ($this->revoked_at !== null) {
            return 'revoked';
        }

        if ($this->completed_at !== null) {
            return 'redeemed';
        }

        if ($this->failed_at !== null) {
            return 'failed';
        }

        if ($this->consumed_at !== null) {
            return 'processing';
        }

        if ($this->expires_at->isPast()) {
            return 'expired';
        }

        return 'ready';
    }
}
