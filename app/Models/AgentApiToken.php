<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AgentApiToken extends Model
{
    /** @use HasFactory<\Database\Factories\AgentApiTokenFactory> */
    use HasFactory, HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'agent_id',
        'team_id',
        'name',
        'token_hash',
        'last_used_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
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

    /**
     * @return array{token: self, plaintext: string}
     */
    public static function createForAgent(Agent $agent): array
    {
        $plaintext = 'prov_'.Str::random(48);

        $token = static::create([
            'agent_id' => $agent->id,
            'team_id' => $agent->team_id,
            'token_hash' => hash('sha256', $plaintext),
        ]);

        return ['token' => $token, 'plaintext' => $plaintext];
    }

    public static function findByToken(string $plaintext): ?self
    {
        return static::where('token_hash', hash('sha256', $plaintext))->first();
    }
}
