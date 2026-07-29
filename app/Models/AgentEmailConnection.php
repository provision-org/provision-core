<?php

namespace App\Models;

use Database\Factories\AgentEmailConnectionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentEmailConnection extends Model
{
    /** @use HasFactory<AgentEmailConnectionFactory> */
    use HasFactory, HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'agent_id',
        'provider',
        'email_address',
        'mailboxkit_inbox_id',
        'mailboxkit_webhook_id',
        'mailboxkit_webhook_secret',
        'app_password',
        'status',
    ];

    /**
     * Secrets must never reach the frontend. These decrypt via the `encrypted`
     * cast, so without hiding them they would serialize in plaintext into any
     * Inertia payload that carries the email connection.
     *
     * @var list<string>
     */
    protected $hidden = [
        'app_password',
        'mailboxkit_webhook_secret',
    ];

    /**
     * A safe boolean the UI can read ("password set") without the secret ever
     * being serialized.
     *
     * @var list<string>
     */
    protected $appends = [
        'has_app_password',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mailboxkit_webhook_secret' => 'encrypted',
            'app_password' => 'encrypted',
        ];
    }

    /**
     * Whether an App Password is on file — a boolean, never the value itself.
     */
    public function getHasAppPasswordAttribute(): bool
    {
        return filled($this->getRawOriginal('app_password'));
    }

    public function isGmail(): bool
    {
        return $this->provider === 'gmail';
    }

    /**
     * @return BelongsTo<Agent, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
