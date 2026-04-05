<?php

namespace App\Models;

use App\Enums\AgentMode;
use App\Enums\AgentRole;
use App\Enums\AgentStatus;
use App\Enums\HarnessType;
use App\Enums\LlmProvider;
use Database\Factories\AgentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Provision\Skills\Models\Skill;

class Agent extends Model
{
    /** @use HasFactory<AgentFactory> */
    use HasFactory, HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'server_id',
        'agent_template_id',
        'harness_type',
        'name',
        'emoji',
        'role',
        'job_description',
        'status',
        'model_primary',
        'model_fallbacks',
        'system_prompt',
        'identity',
        'soul',
        'tools_config',
        'user_context',
        'config_snapshot',
        'harness_agent_id',
        'api_server_port',
        'avatar_path',
        'default_password',
        'is_syncing',
        'last_synced_at',
        'stats_total_sessions',
        'stats_total_messages',
        'stats_tokens_input',
        'stats_tokens_output',
        'stats_last_active_at',
        'stats_synced_at',
        'agent_mode',
        'reports_to',
        'org_title',
        'capabilities',
        'delegation_enabled',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AgentStatus::class,
            'role' => AgentRole::class,
            'agent_mode' => AgentMode::class,
            'harness_type' => HarnessType::class,
            'delegation_enabled' => 'boolean',
            'model_fallbacks' => 'array',
            'default_password' => 'encrypted',
            'config_snapshot' => 'array',
            'is_syncing' => 'boolean',
            'last_synced_at' => 'datetime',
            'stats_last_active_at' => 'datetime',
            'stats_synced_at' => 'datetime',
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
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * @return BelongsTo<AgentTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(AgentTemplate::class, 'agent_template_id');
    }

    /**
     * @return HasOne<AgentSlackConnection, $this>
     */
    public function slackConnection(): HasOne
    {
        return $this->hasOne(AgentSlackConnection::class);
    }

    /**
     * @return HasOne<AgentEmailConnection, $this>
     */
    public function emailConnection(): HasOne
    {
        return $this->hasOne(AgentEmailConnection::class);
    }

    /**
     * @return HasOne<AgentTelegramConnection, $this>
     */
    public function telegramConnection(): HasOne
    {
        return $this->hasOne(AgentTelegramConnection::class);
    }

    /**
     * @return HasOne<AgentDiscordConnection, $this>
     */
    public function discordConnection(): HasOne
    {
        return $this->hasOne(AgentDiscordConnection::class);
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * @return HasMany<AgentTool, $this>
     */
    public function tools(): HasMany
    {
        return $this->hasMany(AgentTool::class);
    }

    /**
     * @return HasMany<AgentActivity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(AgentActivity::class);
    }

    /**
     * @return HasMany<AgentApiToken, $this>
     */
    public function apiTokens(): HasMany
    {
        return $this->hasMany(AgentApiToken::class);
    }

    /**
     * @return HasMany<AgentDailyStat, $this>
     */
    public function dailyStats(): HasMany
    {
        return $this->hasMany(AgentDailyStat::class);
    }

    /**
     * @return HasMany<ChatConversation, $this>
     */
    public function chatConversations(): HasMany
    {
        return $this->hasMany(ChatConversation::class);
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reports_to');
    }

    /**
     * @return HasMany<self, $this>
     */
    public function directReports(): HasMany
    {
        return $this->hasMany(self::class, 'reports_to');
    }

    /**
     * Get the full chain of command from this agent up to the root.
     *
     * @return list<Agent>
     */
    public function chainOfCommand(): array
    {
        $chain = [];
        $current = $this->manager;

        while ($current) {
            $chain[] = $current;
            $current = $current->manager;
        }

        return $chain;
    }

    /**
     * Validate that setting this agent's reports_to would not create a cycle.
     */
    public function validateOrgHierarchy(?string $managerId): bool
    {
        if ($managerId === null) {
            return true;
        }

        if ($managerId === $this->id) {
            return false;
        }

        $visited = [$this->id];
        $current = self::find($managerId);

        while ($current) {
            if (in_array($current->id, $visited, true)) {
                return false;
            }

            $visited[] = $current->id;
            $current = $current->manager;
        }

        return true;
    }

    public function isWorkforce(): bool
    {
        return $this->agent_mode === AgentMode::Workforce;
    }

    public function isChannel(): bool
    {
        return $this->agent_mode === AgentMode::Channel;
    }

    /**
     * @return HasMany<Goal, $this>
     */
    public function ownedGoals(): HasMany
    {
        return $this->hasMany(Goal::class, 'owner_agent_id');
    }

    /**
     * @return HasMany<UsageEvent, $this>
     */
    public function usageEvents(): HasMany
    {
        return $this->hasMany(UsageEvent::class);
    }

    /**
     * @return HasMany<Approval, $this>
     */
    public function approvals(): HasMany
    {
        return $this->hasMany(Approval::class, 'requesting_agent_id');
    }

    /**
     * Skills relationship — only available when provision/module-skills is installed.
     */
    public function skills(): BelongsToMany
    {
        $skillModel = class_exists(Skill::class)
            ? Skill::class
            : self::class; // Fallback to self to avoid crash when module absent

        return $this->belongsToMany($skillModel, 'agent_skills')
            ->withPivot('installed_version', 'installed_at');
    }

    /**
     * Generate a secure random password for agent accounts.
     */
    public static function generateSecurePassword(): string
    {
        $chars = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $special = '!@#$%&*';
        $password = '';
        for ($i = 0; $i < 16; $i++) {
            $password .= $chars[random_int(0, strlen($chars) - 1)];
        }
        // Insert 2 special chars at random positions
        $password[random_int(0, 15)] = $special[random_int(0, strlen($special) - 1)];
        $password[random_int(0, 15)] = $special[random_int(0, strlen($special) - 1)];

        return $password;
    }

    /**
     * Get the model ID formatted for OpenClaw config (prefixed with provider).
     */
    public function openclawModel(): string
    {
        $provider = LlmProvider::forModel($this->model_primary);

        return $provider
            ? $provider->openclawModel($this->model_primary)
            : $this->model_primary;
    }

    /**
     * Get the model config for OpenClaw: a string (no fallbacks) or an object with primary + fallbacks.
     *
     * @return string|array{primary: string, fallbacks: list<string>}
     */
    public function openclawModelConfig(): string|array
    {
        $primary = $this->openclawModel();

        if (empty($this->model_fallbacks)) {
            return $primary;
        }

        $fallbacks = collect($this->model_fallbacks)
            ->map(fn (string $id) => LlmProvider::forModel($id)?->openclawModel($id) ?? $id)
            ->all();

        return ['primary' => $primary, 'fallbacks' => $fallbacks];
    }
}
