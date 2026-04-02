<?php

namespace App\Models;

use App\Enums\DiscordConnectionStatus;
use App\Observers\ChannelConnectionObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ObservedBy(ChannelConnectionObserver::class)]
class AgentDiscordConnection extends Model
{
    /** @use HasFactory<\Database\Factories\AgentDiscordConnectionFactory> */
    use HasFactory, HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'agent_id',
        'token',
        'bot_username',
        'application_id',
        'guild_id',
        'status',
        'dm_policy',
        'group_policy',
        'require_mention',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'token',
    ];

    /**
     * @var list<string>
     */
    protected $appends = [
        'token_masked',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DiscordConnectionStatus::class,
            'token' => 'encrypted',
            'require_mention' => 'boolean',
        ];
    }

    public function getTokenMaskedAttribute(): ?string
    {
        if (! $this->token) {
            return null;
        }

        $token = $this->token;

        if (strlen($token) <= 8) {
            return str_repeat('*', strlen($token));
        }

        return substr($token, 0, 4).str_repeat('*', strlen($token) - 8).substr($token, -4);
    }

    /**
     * @return BelongsTo<Agent, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
