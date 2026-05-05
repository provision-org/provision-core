<?php

namespace App\Models;

use App\Observers\ChannelConnectionObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[ObservedBy(ChannelConnectionObserver::class)]
class AgentWebConnection extends Model
{
    use HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'agent_id',
        'account_id',
        'webhook_secret',
        'api_token',
        'status',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'webhook_secret',
        'api_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'webhook_secret' => 'encrypted',
            'api_token' => 'encrypted',
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
     * Auto-create a connection for an agent with fresh secrets.
     */
    public static function provisionFor(Agent $agent): self
    {
        return self::create([
            'agent_id' => $agent->id,
            'account_id' => 'provision-web-'.$agent->harness_agent_id,
            'webhook_secret' => Str::random(48),
            'api_token' => Str::random(48),
            'status' => 'connected',
        ]);
    }
}
