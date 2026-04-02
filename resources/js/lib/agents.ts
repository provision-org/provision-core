export const roleLabels: Record<string, string> = {
    bdr: 'BDR',
    executive_assistant: 'Executive Assistant',
    frontend_developer: 'Frontend Developer',
    backend_developer: 'Backend Developer',
    researcher: 'Researcher',
    content_writer: 'Content Writer',
    customer_support: 'Customer Support',
    data_analyst: 'Data Analyst',
    project_manager: 'Project Manager',
    design_reviewer: 'Design Reviewer',
    custom: 'Custom',
};

export function relativeTime(dateString: string | null): string {
    if (!dateString) return 'Never';

    const date = new Date(dateString);
    const now = new Date();
    const diffMs = now.getTime() - date.getTime();
    const diffSecs = Math.floor(diffMs / 1000);
    const diffMins = Math.floor(diffSecs / 60);
    const diffHours = Math.floor(diffMins / 60);
    const diffDays = Math.floor(diffHours / 24);

    if (diffSecs < 60) return 'Just now';
    if (diffMins < 60) return `${diffMins}m ago`;
    if (diffHours < 24) return `${diffHours}h ago`;
    return `${diffDays}d ago`;
}

export function formatTokens(count: number): string {
    if (count >= 1_000_000) return `${(count / 1_000_000).toFixed(1)}M`;
    if (count >= 1_000) return `${(count / 1_000).toFixed(1)}K`;
    return count.toString();
}
