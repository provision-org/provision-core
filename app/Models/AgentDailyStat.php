<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentDailyStat extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'agent_id',
        'date',
        'cumulative_tokens_input',
        'cumulative_tokens_output',
        'cumulative_messages',
        'cumulative_sessions',
    ];

    /**
     * @return BelongsTo<Agent, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
