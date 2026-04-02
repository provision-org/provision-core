<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentActivity extends Model
{
    /** @use HasFactory<\Database\Factories\AgentActivityFactory> */
    use HasFactory, HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'agent_id',
        'type',
        'channel',
        'summary',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Agent, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /**
     * @param  Builder<AgentActivity>  $query
     * @return Builder<AgentActivity>
     */
    public function scopeForTeam(Builder $query, string $teamId): Builder
    {
        return $query->whereHas('agent', fn (Builder $q) => $q->where('team_id', $teamId));
    }

    /**
     * @param  Builder<AgentActivity>  $query
     * @return Builder<AgentActivity>
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }
}
