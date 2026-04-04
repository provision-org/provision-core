import { Head, Link } from '@inertiajs/react';
import { Hash, MessageSquare, Network, Users } from 'lucide-react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import AppLayout from '@/layouts/app-layout';
import type { Agent, BreadcrumbItem, Team } from '@/types';

const STATUS_DOT: Record<string, string> = {
    active: 'bg-emerald-500',
    deploying: 'bg-amber-500',
    pending: 'bg-amber-500',
    paused: 'bg-neutral-400',
    error: 'bg-red-500',
};

function AgentAvatar({
    name,
    size = 'md',
}: {
    name: string;
    size?: 'sm' | 'md';
}) {
    const sizeClasses = size === 'sm' ? 'size-8 text-xs' : 'size-10 text-sm';

    return (
        <div
            className={`flex shrink-0 items-center justify-center rounded-full bg-primary/10 font-semibold text-primary ${sizeClasses}`}
        >
            {name.charAt(0).toUpperCase()}
        </div>
    );
}

function WorkforceCard({
    agent,
    agents,
    depth = 0,
}: {
    agent: Agent;
    agents: Agent[];
    depth?: number;
}) {
    const reports = agents.filter(
        (a) =>
            a.agent_mode === 'workforce' &&
            (a as Agent & { reports_to?: string }).reports_to === agent.id,
    );

    const agentExt = agent as Agent & {
        org_title?: string;
        capabilities?: string;
        reports_to?: string;
    };
    const manager = agentExt.reports_to
        ? agents.find((a) => a.id === agentExt.reports_to)
        : null;

    return (
        <>
            <Link
                href={`/agents/${agent.id}`}
                className="block rounded-xl border bg-card p-4 shadow-xs transition-shadow hover:shadow-sm"
                style={{ marginLeft: `${depth * 32}px` }}
            >
                <div className="flex items-start gap-3">
                    <AgentAvatar name={agent.name} />
                    <div className="min-w-0 flex-1">
                        {agentExt.org_title && (
                            <p className="text-sm font-semibold">
                                {agentExt.org_title}
                            </p>
                        )}
                        <p
                            className={`truncate ${agentExt.org_title ? 'text-xs text-muted-foreground' : 'text-sm font-medium'}`}
                        >
                            {agent.name}
                        </p>
                        {agentExt.capabilities && (
                            <p className="mt-1 line-clamp-2 text-xs text-muted-foreground/70">
                                {agentExt.capabilities}
                            </p>
                        )}
                    </div>
                    <Badge className="shrink-0 bg-violet-100 text-[10px] text-violet-700 dark:bg-violet-900/40 dark:text-violet-300">
                        Tasks
                    </Badge>
                </div>
                <div className="mt-3 flex items-center gap-3">
                    <div className="flex items-center gap-1.5">
                        <span
                            className={`size-1.5 rounded-full ${STATUS_DOT[agent.status] ?? 'bg-neutral-400'}`}
                        />
                        <span className="text-[11px] text-muted-foreground capitalize">
                            {agent.status}
                        </span>
                    </div>
                    {manager && (
                        <span className="text-[11px] text-muted-foreground">
                            Reports to: {manager.name}
                        </span>
                    )}
                </div>
            </Link>

            {reports.map((report) => (
                <WorkforceCard
                    key={report.id}
                    agent={report}
                    agents={agents}
                    depth={depth + 1}
                />
            ))}
        </>
    );
}

function ChannelCard({ agent }: { agent: Agent }) {
    return (
        <Link
            href={`/agents/${agent.id}`}
            className="block rounded-xl border border-dashed bg-card/50 p-4 transition-shadow hover:shadow-sm"
        >
            <div className="flex items-start gap-3">
                <div className="flex size-10 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground">
                    <Hash className="size-4" />
                </div>
                <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-medium">{agent.name}</p>
                    <p className="truncate text-xs text-muted-foreground capitalize">
                        {(agent as Agent & { org_title?: string }).org_title ??
                            agent.role?.replace(/_/g, ' ') ??
                            'Chat Agent'}
                    </p>
                </div>
                <Badge className="shrink-0 bg-sky-100 text-[10px] text-sky-700 dark:bg-sky-900/40 dark:text-sky-300">
                    Chat
                </Badge>
            </div>
            <div className="mt-3 flex flex-wrap items-center gap-2">
                <span
                    className={`size-1.5 rounded-full ${STATUS_DOT[agent.status] ?? 'bg-neutral-400'}`}
                />
                <span className="text-[11px] text-muted-foreground capitalize">
                    {agent.status}
                </span>
                {agent.slack_connection?.status === 'connected' && (
                    <Badge variant="outline" className="text-[9px]">
                        Slack
                    </Badge>
                )}
                {agent.telegram_connection?.status === 'connected' && (
                    <Badge variant="outline" className="text-[9px]">
                        Telegram
                    </Badge>
                )}
                {agent.discord_connection?.status === 'connected' && (
                    <Badge variant="outline" className="text-[9px]">
                        Discord
                    </Badge>
                )}
                {agent.email_connection?.email_address && (
                    <Badge variant="outline" className="text-[9px]">
                        Email
                    </Badge>
                )}
            </div>
        </Link>
    );
}

export default function OrgIndex({
    agents,
    team,
}: {
    agents: Agent[];
    team: Team;
}) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Company', href: '/governance/tasks' },
        { title: 'Org Chart', href: '/governance/org' },
    ];

    const workforce = agents.filter((a) => a.agent_mode === 'workforce');
    const channels = agents.filter((a) => a.agent_mode === 'channel');

    // Find root workforce agents (no reports_to, or reports_to not in workforce)
    const workforceIds = new Set(workforce.map((a) => a.id));
    const roots = workforce.filter((a) => {
        const reportsTo = (a as Agent & { reports_to?: string }).reports_to;
        return !reportsTo || !workforceIds.has(reportsTo);
    });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Org Chart" />

            <div className="px-4 py-6 sm:px-6">
                <Heading
                    variant="small"
                    title="Org Chart"
                    description="Visualize your team's agent structure and reporting hierarchy."
                />

                {/* Board */}
                <div className="mt-6 flex flex-col items-center">
                    <div className="rounded-xl border-2 border-primary/20 bg-primary/5 px-8 py-4 text-center">
                        <p className="text-xs font-medium tracking-wider text-muted-foreground uppercase">
                            Team
                        </p>
                        <p className="mt-1 text-lg font-semibold">
                            {team.name}
                        </p>
                    </div>

                    {(workforce.length > 0 || channels.length > 0) && (
                        <div className="h-8 w-px bg-border" />
                    )}
                </div>

                {/* Workforce section */}
                {workforce.length > 0 && (
                    <div className="mt-2">
                        <div className="mb-4 flex items-center gap-2">
                            <Users className="size-4 text-muted-foreground" />
                            <h3 className="text-sm font-medium">Task Agents</h3>
                            <span className="rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground">
                                {workforce.length}
                            </span>
                        </div>
                        <div className="space-y-3">
                            {roots.map((agent) => (
                                <WorkforceCard
                                    key={agent.id}
                                    agent={agent}
                                    agents={agents}
                                />
                            ))}
                        </div>
                    </div>
                )}

                {/* Channel section */}
                {channels.length > 0 && (
                    <div className="mt-8">
                        <div className="mb-4 flex items-center gap-2">
                            <MessageSquare className="size-4 text-muted-foreground" />
                            <h3 className="text-sm font-medium">Chat Agents</h3>
                            <span className="rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground">
                                {channels.length}
                            </span>
                        </div>
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                            {channels.map((agent) => (
                                <ChannelCard key={agent.id} agent={agent} />
                            ))}
                        </div>
                    </div>
                )}

                {agents.length === 0 && (
                    <div className="mt-8 flex flex-col items-center rounded-lg border border-dashed py-16 text-center">
                        <Network className="size-10 text-muted-foreground/40" />
                        <p className="mt-4 text-sm font-medium text-foreground">
                            No agents deployed yet
                        </p>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Deploy your first agent to build your org chart.
                        </p>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
