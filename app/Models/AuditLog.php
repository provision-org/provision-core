<?php

namespace App\Models;

use App\Enums\ActorType;
use Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    /** @use HasFactory<AuditLogFactory> */
    use HasFactory, HasUlids;

    /**
     * Disable automatic timestamps — we only set created_at manually.
     */
    public $timestamps = false;

    /**
     * The table associated with the model.
     */
    protected $table = 'audit_log';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'actor_type',
        'actor_id',
        'action',
        'target_type',
        'target_id',
        'payload',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'actor_type' => ActorType::class,
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Enforce immutability — audit log entries cannot be updated or deleted.
     */
    protected static function booted(): void
    {
        static::updating(function () {
            throw new \RuntimeException('Audit log entries are immutable and cannot be updated.');
        });

        static::deleting(function () {
            throw new \RuntimeException('Audit log entries are immutable and cannot be deleted.');
        });
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
