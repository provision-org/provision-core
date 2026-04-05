<?php

namespace App\Models;

use App\Enums\GoalPriority;
use App\Enums\GoalStatus;
use Database\Factories\GoalFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Goal extends Model
{
    /** @use HasFactory<GoalFactory> */
    use HasFactory, HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'parent_id',
        'owner_agent_id',
        'title',
        'description',
        'status',
        'priority',
        'target_date',
        'progress_pct',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => GoalStatus::class,
            'priority' => GoalPriority::class,
            'target_date' => 'date',
            'progress_pct' => 'integer',
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
     * @return BelongsTo<self, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<self, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return BelongsTo<Agent, $this>
     */
    public function ownerAgent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'owner_agent_id');
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * Calculate progress based on children goals and linked tasks.
     *
     * If the goal has children, progress is the average of child progress.
     * If the goal has no children but has tasks, progress is the percentage of completed tasks.
     */
    public function calculateProgress(): int
    {
        $childCount = $this->children()->count();

        if ($childCount > 0) {
            $achievedCount = $this->children()
                ->where('status', GoalStatus::Achieved->value)
                ->count();

            return $childCount > 0 ? (int) round(($achievedCount / $childCount) * 100) : 0;
        }

        $taskCount = $this->tasks()->count();

        if ($taskCount > 0) {
            $doneCount = $this->tasks()
                ->where('status', 'done')
                ->count();

            return (int) round(($doneCount / $taskCount) * 100);
        }

        return $this->progress_pct;
    }
}
