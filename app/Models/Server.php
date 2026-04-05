<?php

namespace App\Models;

use App\Enums\CloudProvider;
use App\Enums\ServerStatus;
use Database\Factories\ServerFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Server extends Model
{
    /** @use HasFactory<ServerFactory> */
    use HasFactory, HasUlids;

    /**
     * @var list<string>
     */
    /**
     * @var list<string>
     */
    protected $hidden = [
        'root_password',
        'gateway_token',
        'vnc_password',
        'daemon_token',
    ];

    protected $fillable = [
        'team_id',
        'name',
        'cloud_provider',
        'provider_server_id',
        'provider_volume_id',
        'ipv4_address',
        'server_type',
        'region',
        'image',
        'status',
        'provisioned_at',
        'openclaw_version',
        'last_health_check',
        'daemon_token',
    ];

    // Secrets set explicitly in jobs, never via mass assignment
    // gateway_token, root_password, vnc_password

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cloud_provider' => CloudProvider::class,
            'status' => ServerStatus::class,
            'gateway_token' => 'encrypted',
            'root_password' => 'encrypted',
            'vnc_password' => 'encrypted',
            'daemon_token' => 'encrypted',
            'provisioned_at' => 'datetime',
            'last_health_check' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return HasMany<ServerEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(ServerEvent::class);
    }

    /**
     * @return HasMany<Agent, $this>
     */
    public function agents(): HasMany
    {
        return $this->hasMany(Agent::class);
    }

    /**
     * @return HasOne<GatewayConfig, $this>
     */
    public function gatewayConfig(): HasOne
    {
        return $this->hasOne(GatewayConfig::class);
    }

    public function isDocker(): bool
    {
        return $this->cloud_provider === CloudProvider::Docker;
    }
}
