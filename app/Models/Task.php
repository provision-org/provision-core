<?php

namespace App\Models;

use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory, HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'agent_id',
        'identifier',
        'created_by_type',
        'created_by_id',
        'title',
        'description',
        'status',
        'priority',
        'tags',
        'sort_order',
        'completed_at',
        'parent_task_id',
        'goal_id',
        'routine_id',
        'checked_out_by_run',
        'checked_out_at',
        'checkout_expires_at',
        'delegated_by',
        'request_depth',
        'tokens_input',
        'tokens_output',
        'result_summary',
        'started_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'completed_at' => 'datetime',
            'checked_out_at' => 'datetime',
            'checkout_expires_at' => 'datetime',
            'started_at' => 'datetime',
            'request_depth' => 'integer',
            'tokens_input' => 'integer',
            'tokens_output' => 'integer',
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
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /**
     * @return HasMany<TaskNote, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(TaskNote::class);
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function parentTask(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_task_id');
    }

    /**
     * @return HasMany<self, $this>
     */
    public function subTasks(): HasMany
    {
        return $this->hasMany(self::class, 'parent_task_id');
    }

    /**
     * @return BelongsTo<Goal, $this>
     */
    public function goal(): BelongsTo
    {
        return $this->belongsTo(Goal::class);
    }

    /**
     * @return BelongsTo<Routine, $this>
     */
    public function routine(): BelongsTo
    {
        return $this->belongsTo(Routine::class);
    }

    /**
     * The workforce agent assigned to this task.
     *
     * @return BelongsTo<Agent, $this>
     */
    public function assignedAgent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'agent_id');
    }

    /**
     * The agent that delegated this task.
     *
     * @return BelongsTo<Agent, $this>
     */
    public function delegatedByAgent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'delegated_by');
    }

    /**
     * @return HasMany<UsageEvent, $this>
     */
    public function usageEvents(): HasMany
    {
        return $this->hasMany(UsageEvent::class);
    }

    /**
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public function scopeForTeam(Builder $query, string $teamId): Builder
    {
        return $query->where('team_id', $teamId);
    }

    /**
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public function scopeByStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public function scopeByPriority(Builder $query, string $priority): Builder
    {
        return $query->where('priority', $priority);
    }
}
