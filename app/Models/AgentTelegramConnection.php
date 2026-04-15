<?php

namespace App\Models;

use App\Enums\TelegramConnectionStatus;
use App\Observers\ChannelConnectionObserver;
use Database\Factories\AgentTelegramConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ObservedBy(ChannelConnectionObserver::class)]
class AgentTelegramConnection extends Model
{
    /** @use HasFactory<AgentTelegramConnectionFactory> */
    use HasFactory, HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'agent_id',
        'bot_token',
        'bot_username',
        'status',
        'dm_policy',
        'last_chat_id',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'bot_token',
    ];

    /**
     * @var list<string>
     */
    protected $appends = [
        'bot_token_masked',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TelegramConnectionStatus::class,
            'bot_token' => 'encrypted',
        ];
    }

    public function getBotTokenMaskedAttribute(): ?string
    {
        if (! $this->bot_token) {
            return null;
        }

        $token = $this->bot_token;

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
