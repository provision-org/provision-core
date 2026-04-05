<?php

namespace App\Models;

use App\Enums\ApprovalStatus;
use App\Enums\ApprovalType;
use Database\Factories\ApprovalFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Approval extends Model
{
    /** @use HasFactory<ApprovalFactory> */
    use HasFactory, HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'requesting_agent_id',
        'type',
        'status',
        'title',
        'payload',
        'linked_task_id',
        'reviewed_by',
        'reviewed_at',
        'review_note',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ApprovalType::class,
            'status' => ApprovalStatus::class,
            'payload' => 'array',
            'reviewed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return BelongsTo<Agent, $this>
     */
    public function requestingAgent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'requesting_agent_id');
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function linkedTask(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'linked_task_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isPending(): bool
    {
        return $this->status === ApprovalStatus::Pending;
    }

    public function isResolved(): bool
    {
        return in_array($this->status, [
            ApprovalStatus::Approved,
            ApprovalStatus::Rejected,
        ], true);
    }

    /**
     * @param  Builder<Approval>  $query
     * @return Builder<Approval>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', ApprovalStatus::Pending->value);
    }

    /**
     * @param  Builder<Approval>  $query
     * @return Builder<Approval>
     */
    public function scopeForAgent(Builder $query, string $agentId): Builder
    {
        return $query->where('requesting_agent_id', $agentId);
    }
}
