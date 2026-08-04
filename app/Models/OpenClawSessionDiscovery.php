<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpenClawSessionDiscovery extends Model
{
    use HasUlids;

    public const IMPORT_WINDOW_MINUTES = 15;

    protected $table = 'openclaw_session_discoveries';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'server_id',
        'agent_id',
        'session_key',
        'kind',
        'channel',
        'chat_type',
        'title',
        'preview',
        'has_active_run',
        'active_run_ids',
        'upstream_updated_at',
        'discovered_at',
        'claimed_by_user_id',
        'chat_conversation_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'has_active_run' => 'boolean',
            'active_run_ids' => 'array',
            'upstream_updated_at' => 'datetime',
            'discovered_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * @return BelongsTo<Agent, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function claimedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimed_by_user_id');
    }

    /**
     * @return BelongsTo<ChatConversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'chat_conversation_id');
    }

    public function canSend(): bool
    {
        if ($this->kind !== 'direct') {
            return false;
        }

        return $this->channel === null
            || in_array($this->channel, ['webchat', 'control-ui'], true);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeUnclaimedAndFresh(Builder $query): Builder
    {
        return $query
            ->whereNull('claimed_by_user_id')
            ->whereNull('chat_conversation_id')
            ->where('discovered_at', '>=', now()->subMinutes(self::IMPORT_WINDOW_MINUTES));
    }

    public function isExpiredForImport(): bool
    {
        if ($this->claimed_by_user_id !== null || $this->chat_conversation_id !== null) {
            return false;
        }

        return $this->discovered_at === null
            || $this->discovered_at->lt(now()->subMinutes(self::IMPORT_WINDOW_MINUTES));
    }
}
