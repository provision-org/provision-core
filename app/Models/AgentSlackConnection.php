<?php

namespace App\Models;

use App\Enums\SlackConnectionStatus;
use App\Observers\ChannelConnectionObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ObservedBy(ChannelConnectionObserver::class)]
class AgentSlackConnection extends Model
{
    /** @use HasFactory<\Database\Factories\AgentSlackConnectionFactory> */
    use HasFactory, HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'agent_id',
        'slack_app_id',
        'bot_token',
        'app_token',
        'status',
        'allowed_channels',
        'slack_team_id',
        'slack_bot_user_id',
        'client_id',
        'client_secret',
        'signing_secret',
        'oauth_state',
        'is_automated',
        'dm_policy',
        'group_policy',
        'require_mention',
        'reply_to_mode',
        'dm_session_scope',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'bot_token',
        'app_token',
        'client_id',
        'client_secret',
        'signing_secret',
        'oauth_state',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SlackConnectionStatus::class,
            'bot_token' => 'encrypted',
            'app_token' => 'encrypted',
            'client_id' => 'encrypted',
            'client_secret' => 'encrypted',
            'signing_secret' => 'encrypted',
            'allowed_channels' => 'array',
            'is_automated' => 'boolean',
            'require_mention' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Agent, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
