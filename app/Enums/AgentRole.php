<?php

namespace App\Enums;

enum AgentRole: string
{
    case Bdr = 'bdr';
    case ExecutiveAssistant = 'executive_assistant';
    case FrontendDeveloper = 'frontend_developer';
    case BackendDeveloper = 'backend_developer';
    case Researcher = 'researcher';
    case ContentWriter = 'content_writer';
    case CustomerSupport = 'customer_support';
    case DataAnalyst = 'data_analyst';
    case ProjectManager = 'project_manager';
    case DesignReviewer = 'design_reviewer';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Bdr => 'BDR',
            self::ExecutiveAssistant => 'Executive Assistant',
            self::FrontendDeveloper => 'Frontend Developer',
            self::BackendDeveloper => 'Backend Developer',
            self::Researcher => 'Researcher',
            self::ContentWriter => 'Content Writer',
            self::CustomerSupport => 'Customer Support',
            self::DataAnalyst => 'Data Analyst',
            self::ProjectManager => 'Project Manager',
            self::DesignReviewer => 'Design Reviewer',
            self::Custom => 'Custom',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Bdr => 'Prospect outreach, lead qualification, and pipeline management.',
            self::ExecutiveAssistant => 'Scheduling, email drafting, and task organization.',
            self::FrontendDeveloper => 'UI development, code review, and accessibility audits.',
            self::BackendDeveloper => 'API design, database work, and server-side logic.',
            self::Researcher => 'Information gathering, analysis, and report generation.',
            self::ContentWriter => 'Blog posts, marketing copy, and documentation.',
            self::CustomerSupport => 'Ticket triage, customer replies, and FAQ management.',
            self::DataAnalyst => 'Data exploration, visualization, and insight generation.',
            self::ProjectManager => 'Task tracking, status updates, and team coordination.',
            self::DesignReviewer => 'UI/UX feedback, design system audits, and accessibility checks.',
            self::Custom => 'Start from scratch with a blank configuration.',
        };
    }

    public function avatarPrompt(): string
    {
        $roleDetail = match ($this) {
            self::ProjectManager => 'interconnected nodes forming a constellation pattern, deep blue and white, lines connecting circular points in an organized network',
            self::BackendDeveloper => 'layered geometric circuit pattern, dark orange and charcoal, angular interlocking shapes suggesting architecture and structure',
            self::ContentWriter => 'flowing ribbon-like curves forming an abstract quill shape, warm burgundy and cream, elegant swooping lines with soft gradients',
            self::FrontendDeveloper => 'overlapping translucent rounded rectangles in a grid, cyan and soft purple, clean UI-inspired geometric composition',
            self::DesignReviewer => 'concentric circles with a focal point off-center, plum and silver, precise rings suggesting focus and attention to detail',
            self::Bdr => 'sharp upward-pointing chevrons in a dynamic stack, emerald green and gold, bold angular forms suggesting momentum and direction',
            self::CustomerSupport => 'interlocking semicircles forming a shield-like pattern, teal and warm peach, rounded protective shapes suggesting care',
            self::DataAnalyst => 'ascending bar chart abstracted into crystal-like faceted columns, slate blue and mint green, clean geometric data visualization',
            self::ExecutiveAssistant => 'radiating lines from a central point like a compass rose, navy and champagne gold, precise symmetrical pattern suggesting organization',
            self::Researcher => 'spiral golden ratio pattern with floating dot accents, indigo and amber, mathematical organic form suggesting discovery',
            self::Custom => 'simple hexagonal tessellation with soft gradient fill, neutral grey and soft purple, clean versatile geometric pattern',
        };

        return "Abstract geometric avatar, {$roleDetail}, dark background with subtle gradient, minimal clean design, no text, no faces, no people, professional app icon style, smooth vector-like render, 1024x1024";
    }
}
