<?php

namespace App\Models;

use App\Enums\AgentRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgentTemplate extends Model
{
    /** @use HasFactory<\Database\Factories\AgentTemplateFactory> */
    use HasFactory, HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'slug',
        'name',
        'tagline',
        'emoji',
        'role',
        'system_prompt',
        'identity',
        'soul',
        'tools_config',
        'user_context',
        'model_primary',
        'recommended_tools',
        'avatar_path',
        'model_fallbacks',
        'is_active',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => AgentRole::class,
            'recommended_tools' => 'array',
            'model_fallbacks' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * @param  Builder<AgentTemplate>  $query
     * @return Builder<AgentTemplate>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @return HasMany<Agent, $this>
     */
    public function agents(): HasMany
    {
        return $this->hasMany(Agent::class);
    }
}
