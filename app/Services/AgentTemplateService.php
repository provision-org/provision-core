<?php

namespace App\Services;

use App\Enums\AgentRole;
use App\Models\Team;
use App\Models\User;
use Carbon\Carbon;

class AgentTemplateService
{
    /**
     * @return array{soul: string, system_prompt: string, tools_config: string, user_context: string}
     */
    public function getTemplate(AgentRole $role, ?User $user = null, ?Team $team = null): array
    {
        if ($role === AgentRole::Custom) {
            return [
                'soul' => '',
                'system_prompt' => '',
                'tools_config' => '',
                'user_context' => $user ? $this->generateUserContext($user, $team) : '',
            ];
        }

        $static = $this->getStaticTemplate($role);

        return [
            'soul' => $static['soul'],
            'system_prompt' => $static['system_prompt'],
            'tools_config' => '',
            'user_context' => $user ? $this->generateUserContext($user, $team) : $static['user_context'],
        ];
    }

    /**
     * @return array<int, array{value: string, label: string, description: string}>
     */
    public function availableRoles(): array
    {
        return array_map(fn (AgentRole $role) => [
            'value' => $role->value,
            'label' => $role->label(),
            'description' => $role->description(),
        ], AgentRole::cases());
    }

    public function generateUserContext(User $user, ?Team $team = null): string
    {
        $lines = ['# USER.md - About Your Human', ''];
        $lines[] = "- **Name:** {$user->name}";
        $lines[] = '- **What to call them:** '.explode(' ', $user->name)[0];

        if ($user->pronouns) {
            $lines[] = "- **Pronouns:** {$user->pronouns}";
        }

        if ($user->timezone) {
            $lines[] = "- **Timezone:** {$user->timezone} — Always use this timezone when discussing times, scheduling, or displaying dates. Never reference UTC unless explicitly asked.";
        }

        if ($user->email) {
            $lines[] = "- **Email:** {$user->email}";
        }

        $team ??= $user->currentTeam;

        if ($team && ($team->company_name || $team->company_description || $team->target_market)) {
            $lines[] = '';
            $lines[] = '## Context';
            $lines[] = '';

            if ($team->company_name) {
                $lines[] = "- **Company:** {$team->company_name}";
            }

            if ($team->company_url) {
                $lines[] = "- **Website:** {$team->company_url}";
            }

            if ($team->company_description) {
                $lines[] = "- **What they do:** {$team->company_description}";
            }

            if ($team->target_market) {
                $lines[] = "- **Target market:** {$team->target_market}";
            }
        }

        return implode("\n", $lines);
    }

    public function generateIdentity(
        string $name,
        ?AgentRole $role = null,
        string $email = '',
        string $emoji = '',
        string $personality = '',
        string $style = '',
        string $backstory = '',
    ): string {
        $heading = $emoji ? "# {$name} {$emoji} - Identity" : "# {$name} - Identity";

        $lines = [$heading, '', '## Core Identity'];
        $lines[] = "- **Name:** {$name}";

        if ($emoji) {
            $lines[] = "- **Emoji:** {$emoji}";
        }

        if ($role && $role !== AgentRole::Custom) {
            $lines[] = "- **Role:** {$role->label()}";
        }

        if ($personality) {
            $lines[] = "- **Personality:** {$personality}";
        }

        if ($style) {
            $lines[] = "- **Style:** {$style}";
        }

        $lines[] = '- **DOB:** '.Carbon::now()->format('M j, Y');

        if ($email) {
            $lines[] = "- **Email:** {$email}";
        }

        if ($backstory) {
            $lines[] = '';
            $lines[] = '## Backstory';
            $lines[] = $backstory;
        }

        $lines[] = '';
        $lines[] = '## Communication Philosophy';
        $lines[] = '- Be genuinely helpful, not performatively helpful';
        $lines[] = '- Have opinions and personality';
        $lines[] = '- Be resourceful before asking';
        $lines[] = '- Earn trust through competence';

        $lines[] = '';
        $lines[] = '## Boundaries';
        $lines[] = '- Private things stay private';
        $lines[] = '- When in doubt, ask before acting externally';
        $lines[] = '- Never send half-baked messages on your behalf';

        return implode("\n", $lines);
    }

    /**
     * @return array{soul: string, system_prompt: string, user_context: string}
     */
    private function getStaticTemplate(AgentRole $role): array
    {
        $rolePath = storage_path("app/agent-templates/{$role->value}");
        $basePath = storage_path('app/agent-templates/_base');

        $roleAgents = $this->readTemplate("{$rolePath}/agents.md");
        $baseAgents = $this->readTemplate("{$basePath}/agents.md");

        $systemPrompt = $roleAgents;
        if ($baseAgents) {
            $systemPrompt = $roleAgents."\n\n".$baseAgents;
        }

        return [
            'soul' => $this->readTemplate("{$rolePath}/soul.md"),
            'system_prompt' => $systemPrompt,
            'user_context' => $this->readTemplate("{$rolePath}/user.md"),
        ];
    }

    private function readTemplate(string $path): string
    {
        if (! file_exists($path)) {
            return '';
        }

        return trim(file_get_contents($path));
    }
}
