import { Head } from '@inertiajs/react';
import { Hash, MessageSquare, Users } from 'lucide-react';
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

function WorkforceCard({ agent }: { agent: Agent }) {
    return (
        <div className="rounded-xl border bg-card p-4 shadow-xs transition-shadow hover:shadow-sm">
            <div className="flex items-start gap-3">
                <div className="flex size-10 shrink-0 items-center justify-center rounded-full bg-primary/10 text-sm font-semibold text-primary">
                    {agent.name.charAt(0).toUpperCase()}
                </div>
                <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-medium">{agent.name}</p>
                    <p className="truncate text-xs text-muted-foreground capitalize">
                        {agent.role?.replace(/_/g, ' ') ?? 'Agent'}
                    </p>
                </div>
            </div>
            <div className="mt-3 flex items-center gap-1.5">
                <span
                    className={`size-1.5 rounded-full ${STATUS_DOT[agent.status] ?? 'bg-neutral-400'}`}
                />
                <span className="text-[11px] text-muted-foreground capitalize">
                    {agent.status}
                </span>
            </div>
        </div>
    );
}

function ChannelCard({ agent }: { agent: Agent }) {
    return (
        <div className="rounded-xl border border-dashed bg-card/50 p-4 transition-shadow hover:shadow-sm">
            <div className="flex items-start gap-3">
                <div className="flex size-10 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground">
                    <Hash className="size-4" />
                </div>
                <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-medium">{agent.name}</p>
                    <p className="truncate text-xs text-muted-foreground capitalize">
                        {agent.role?.replace(/_/g, ' ') ?? 'Channel Agent'}
                    </p>
                </div>
            </div>
            <div className="mt-3 flex items-center gap-2">
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
        </div>
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
        { title: 'Governance', href: '/governance/tasks' },
        { title: 'Org Chart', href: '/governance/org' },
    ];

    const workforce = agents.filter((a) => a.agent_mode === 'workforce');
    const channels = agents.filter((a) => a.agent_mode === 'channel');

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Org Chart" />

            <div className="px-4 py-6 sm:px-6">
                <Heading
                    variant="small"
                    title="Org Chart"
                    description="Visualize your team's agent structure."
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
                            <h3 className="text-sm font-medium">
                                Workforce Agents
                            </h3>
                            <span className="rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground">
                                {workforce.length}
                            </span>
                        </div>
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                            {workforce.map((agent) => (
                                <WorkforceCard key={agent.id} agent={agent} />
                            ))}
                        </div>
                    </div>
                )}

                {/* Channel section */}
                {channels.length > 0 && (
                    <div className="mt-8">
                        <div className="mb-4 flex items-center gap-2">
                            <MessageSquare className="size-4 text-muted-foreground" />
                            <h3 className="text-sm font-medium">
                                Channel Agents
                            </h3>
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
                    <div className="mt-8 rounded-lg border border-dashed py-12 text-center text-sm text-muted-foreground">
                        No agents deployed yet.
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
