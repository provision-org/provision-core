<?php

namespace App\Models;

use App\Enums\ArtifactType;
use App\Enums\ArtifactVisibility;
use Database\Factories\AgentArtifactFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentArtifact extends Model
{
    /** @use HasFactory<AgentArtifactFactory> */
    use HasFactory, HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'agent_id',
        'team_id',
        'name',
        'path_slug',
        'type',
        'source_dir',
        'start_command',
        'port',
        'visibility',
        'access_token',
        'status',
        'error_message',
        'public_url',
        'last_published_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ArtifactType::class,
            'visibility' => ArtifactVisibility::class,
            'port' => 'integer',
            'last_published_at' => 'datetime',
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
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function isGated(): bool
    {
        return $this->visibility === ArtifactVisibility::Gated;
    }
}
