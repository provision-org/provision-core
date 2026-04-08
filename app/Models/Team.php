<?php

namespace App\Models;

use App\Enums\CloudProvider;
use App\Enums\GovernanceMode;
use App\Enums\HarnessType;
use App\Enums\TeamRole;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use HasFactory, HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'personal_team',
        'timezone',
        'harness_type',
        'cloud_provider',
        'company_name',
        'company_url',
        'company_description',
        'target_market',
        'governance_mode',
        'plan',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'personal_team' => 'boolean',
            'harness_type' => HarnessType::class,
            'cloud_provider' => CloudProvider::class,
            'governance_mode' => GovernanceMode::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_user')
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * @return HasMany<TeamInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(TeamInvitation::class);
    }

    /**
     * @return HasOne<Server, $this>
     */
    public function server(): HasOne
    {
        return $this->hasOne(Server::class);
    }

    /**
     * @return HasMany<Agent, $this>
     */
    public function agents(): HasMany
    {
        return $this->hasMany(Agent::class);
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * @return HasMany<Routine, $this>
     */
    public function routines(): HasMany
    {
        return $this->hasMany(Routine::class);
    }

    /**
     * @return HasMany<Goal, $this>
     */
    public function goals(): HasMany
    {
        return $this->hasMany(Goal::class);
    }

    /**
     * @return HasMany<Approval, $this>
     */
    public function approvals(): HasMany
    {
        return $this->hasMany(Approval::class);
    }

    /**
     * @return HasMany<AuditLog, $this>
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    /**
     * @return HasMany<UsageEvent, $this>
     */
    public function usageEvents(): HasMany
    {
        return $this->hasMany(UsageEvent::class);
    }

    /**
     * @return HasMany<TeamApiKey, $this>
     */
    public function apiKeys(): HasMany
    {
        return $this->hasMany(TeamApiKey::class);
    }

    /**
     * @return HasMany<TeamApiKey, $this>
     */
    public function cloudApiKeys(): HasMany
    {
        return $this->apiKeys()->where('provider_type', 'cloud');
    }

    /**
     * @return HasMany<TeamApiKey, $this>
     */
    public function llmApiKeys(): HasMany
    {
        return $this->apiKeys()->where('provider_type', 'llm');
    }

    /**
     * @return HasMany<TeamEnvVar, $this>
     */
    public function envVars(): HasMany
    {
        return $this->hasMany(TeamEnvVar::class);
    }

    /**
     * @return HasOne<ManagedApiKey, $this>
     */
    public function managedApiKey(): HasOne
    {
        return $this->hasOne(ManagedApiKey::class);
    }

    /**
     * @return HasOne<SlackConfigurationToken, $this>
     */
    public function slackConfigurationToken(): HasOne
    {
        return $this->hasOne(SlackConfigurationToken::class);
    }

    public function hasUser(User $user): bool
    {
        return $this->members()->where('user_id', $user->id)->exists();
    }

    public function hasUserWithRole(User $user, TeamRole $role): bool
    {
        return $this->members()
            ->where('user_id', $user->id)
            ->wherePivot('role', $role->value)
            ->exists();
    }

    public function cloudProvider(): CloudProvider
    {
        return $this->cloud_provider ?? CloudProvider::from(config('cloud.default_provider'));
    }

    public function serverType(): string
    {
        $provider = $this->cloudProvider();

        return match ($provider) {
            CloudProvider::DigitalOcean => 's-4vcpu-8gb',
            CloudProvider::Linode => 'g6-standard-4',
            default => 'cpx21',
        };
    }

    public function volumeSize(): int
    {
        return 10;
    }
}
