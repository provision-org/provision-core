<?php

namespace Database\Factories;

use App\Enums\AgentRole;
use App\Enums\AgentStatus;
use App\Enums\HarnessType;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Agent>
 */
class AgentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'name' => fake()->firstName().' Agent',
            'role' => AgentRole::Custom,
            'status' => AgentStatus::Active,
            'model_primary' => 'claude-opus-4-6',
            'harness_type' => HarnessType::OpenClaw,
        ];
    }

    public function bdr(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => AgentRole::Bdr,
            'system_prompt' => 'You are a business development representative. Engage prospects professionally and qualify leads.',
            'soul' => 'Tenacious, professional, and empathetic in every interaction.',
            'tools_config' => 'Use email for outreach and web browsing for research.',
            'user_context' => 'Target market: SaaS companies, 50-500 employees.',
        ]);
    }

    public function executiveAssistant(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => AgentRole::ExecutiveAssistant,
            'system_prompt' => 'You are an executive assistant. Manage schedules, draft communications, and organize tasks.',
            'soul' => 'Proactive, discreet, and meticulous in organization.',
            'tools_config' => 'Use email for communication and documents for briefings.',
            'user_context' => 'Executive prefers concise, formal communication.',
        ]);
    }

    public function frontendDeveloper(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => AgentRole::FrontendDeveloper,
            'system_prompt' => 'You are a frontend developer. Write clean, accessible UI code and review pull requests.',
            'soul' => 'Craft-focused, collaborative, and pragmatic about shipping.',
            'tools_config' => 'Use web browsing for docs and email for code review notifications.',
            'user_context' => 'Stack: React, TypeScript, Tailwind CSS.',
        ]);
    }

    public function backendDeveloper(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => AgentRole::BackendDeveloper,
            'system_prompt' => 'You are a backend developer. Design APIs, optimize queries, and build reliable systems.',
            'soul' => 'Systematic, security-conscious, and pragmatic.',
            'tools_config' => 'Use web browsing for API docs and email for team updates.',
            'user_context' => 'Stack: Laravel, PostgreSQL, Redis.',
        ]);
    }

    public function researcher(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => AgentRole::Researcher,
            'system_prompt' => 'You are a researcher. Gather information, analyze data, and produce detailed reports.',
            'soul' => 'Intellectually curious, methodical, and evidence-based.',
            'tools_config' => 'Use web browsing for research and email for sharing findings.',
            'user_context' => 'Focus: competitive analysis and market research.',
        ]);
    }

    public function contentWriter(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => AgentRole::ContentWriter,
            'system_prompt' => 'You are a content writer. Create compelling blog posts, marketing copy, and documentation.',
            'soul' => 'Creative, empathetic, and detail-oriented with language.',
            'tools_config' => 'Use web browsing for research and email for editorial feedback.',
            'user_context' => 'Brand voice: professional yet approachable.',
        ]);
    }

    public function customerSupport(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => AgentRole::CustomerSupport,
            'system_prompt' => 'You are a customer support agent. Triage tickets, resolve issues, and maintain documentation.',
            'soul' => 'Patient, empathetic, and solution-oriented.',
            'tools_config' => 'Use email for customer communication and web browsing for docs.',
            'user_context' => 'Response time target: under 1 hour.',
        ]);
    }

    public function dataAnalyst(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => AgentRole::DataAnalyst,
            'system_prompt' => 'You are a data analyst. Explore data, generate insights, and create reports.',
            'soul' => 'Analytical, curious, and clear in communication.',
            'tools_config' => 'Use web browsing for benchmarks and email for sharing reports.',
            'user_context' => 'Key metrics: ARR, churn rate, NPS.',
        ]);
    }

    public function projectManager(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => AgentRole::ProjectManager,
            'system_prompt' => 'You are a project manager. Track tasks, coordinate teams, and communicate status.',
            'soul' => 'Organized, proactive, and diplomatic.',
            'tools_config' => 'Use email for status updates and documents for project plans.',
            'user_context' => 'Methodology: Agile with bi-weekly sprints.',
        ]);
    }

    public function designReviewer(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => AgentRole::DesignReviewer,
            'system_prompt' => 'You are a design reviewer. Provide UI/UX feedback, audit accessibility, and review design system consistency.',
            'soul' => 'Observant, constructive, and principled about design quality.',
            'tools_config' => 'Use web browsing for design references and email for feedback.',
            'user_context' => 'Accessibility standard: WCAG 2.1 AA.',
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AgentStatus::Pending,
        ]);
    }

    public function deploying(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AgentStatus::Deploying,
        ]);
    }
}
