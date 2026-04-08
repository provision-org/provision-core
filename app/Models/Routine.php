<?php

namespace App\Models;

use Carbon\Carbon;
use Cron\CronExpression;
use Database\Factories\RoutineFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Routine extends Model
{
    /** @use HasFactory<RoutineFactory> */
    use HasFactory, HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'agent_id',
        'title',
        'description',
        'cron_expression',
        'timezone',
        'status',
        'last_run_at',
        'next_run_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_run_at' => 'datetime',
            'next_run_at' => 'datetime',
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
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * Compute the next run time based on the cron expression and timezone.
     */
    public function computeNextRun(): ?Carbon
    {
        try {
            $next = CronExpression::factory($this->cron_expression)
                ->getNextRunDate(now(), 0, false, $this->timezone);

            return Carbon::instance($next);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Determine if this routine is due to run.
     */
    public function isDue(): bool
    {
        return $this->next_run_at && $this->next_run_at->lte(now());
    }
}
