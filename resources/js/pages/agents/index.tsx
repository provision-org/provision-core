import { Head, Link } from '@inertiajs/react';
import { Bot, Library, Mail, Plus } from 'lucide-react';
import AgentAvatar from '@/components/agents/agent-avatar';
import {
    TelegramIcon,
    SlackIcon,
    DiscordIcon,
} from '@/components/agents/channel-icons';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import AppLayout from '@/layouts/app-layout';
import { relativeTime } from '@/lib/agents';
import type { Agent, BreadcrumbItem, Server } from '@/types';

function ServerStatusIndicator({ server }: { server: Server }) {
    const colors: Record<string, string> = {
        provisioning: 'bg-yellow-500',
        setup_complete: 'bg-blue-500',
        running: 'bg-green-500',
        stopped: 'bg-neutral-400',
        error: 'bg-red-500',
        destroying: 'bg-red-400',
    };

    return (
        <Badge variant="outline" className="gap-1.5 font-normal">
            <span
                className={`inline-block h-1.5 w-1.5 rounded-full ${colors[server.status] ?? 'bg-neutral-400'}`}
            />
            {server.name} ({server.status})
        </Badge>
    );
}

function ChannelIcons({ agent }: { agent: Agent }) {
    const channels: {
        key: string;
        label: string;
        icon: React.ReactNode;
        active: boolean;
    }[] = [
        {
            key: 'email',
            label: 'Email',
            icon: <Mail className="size-3.5" />,
            active: !!agent.email_connection?.email_address,
        },
        {
            key: 'slack',
            label: 'Slack',
            icon: <SlackIcon className="size-3.5" />,
            active: agent.slack_connection?.status === 'connected',
        },
        {
            key: 'telegram',
            label: 'Telegram',
            icon: <TelegramIcon className="size-3.5" />,
            active: agent.telegram_connection?.status === 'connected',
        },
        {
            key: 'discord',
            label: 'Discord',
            icon: <DiscordIcon className="size-3.5" />,
            active: agent.discord_connection?.status === 'connected',
        },
    ];

    const activeChannels = channels.filter((c) => c.active);

    if (activeChannels.length === 0) {
        return <span className="text-muted-foreground">&mdash;</span>;
    }

    return (
        <TooltipProvider>
            <div className="flex items-center gap-1.5">
                {activeChannels.map((channel) => (
                    <Tooltip key={channel.key}>
                        <TooltipTrigger asChild>
                            <span className="text-muted-foreground">
                                {channel.icon}
                            </span>
                        </TooltipTrigger>
                        <TooltipContent side="top">
                            {channel.label}
                        </TooltipContent>
                    </Tooltip>
                ))}
            </div>
        </TooltipProvider>
    );
}

function EmptyState({ subscribed }: { subscribed: boolean }) {
    return (
        <div className="relative mt-4 flex min-h-[50vh] flex-col items-center justify-center overflow-hidden rounded-2xl border border-dashed border-foreground/15 bg-background/50 px-6 py-12 backdrop-blur-sm sm:px-12">
            <div className="pointer-events-none absolute inset-0 flex items-center justify-center opacity-30 mix-blend-screen">
                <div className="h-[300px] w-[300px] rounded-full bg-primary/20 blur-[80px]" />
            </div>

            <div className="relative z-10 mx-auto flex size-16 items-center justify-center rounded-2xl border border-foreground/10 bg-background shadow-sm ring-1 ring-foreground/5">
                <Bot className="size-8 text-primary/80" />
            </div>

            <h3 className="relative z-10 mt-6 text-2xl font-semibold tracking-tight text-foreground">
                Deploy your first AI employee
            </h3>

            <p className="relative z-10 mt-3 max-w-md text-center text-[15px] leading-relaxed text-muted-foreground">
                Create an AI employee with its own email, browser, and channel
                access. Teach it your specific workflows and let it work
                alongside your team.
            </p>

            <div className="relative z-10 mt-8 flex flex-col items-center gap-3 sm:flex-row">
                {subscribed ? (
                    <>
                        <Button
                            asChild
                            size="lg"
                            className="rounded-full shadow-sm transition-transform hover:scale-105"
                        >
                            <Link href="/agents/create">
                                <Plus className="mr-2 size-4" />
                                Create AI Employee
                            </Link>
                        </Button>
                        <Button
                            variant="outline"
                            size="lg"
                            asChild
                            className="rounded-full bg-background transition-transform hover:scale-105 hover:bg-muted"
                        >
                            <Link href="/agents/library">
                                <Library className="mr-2 size-4" />
                                Browse Templates
                            </Link>
                        </Button>
                    </>
                ) : (
                    <Button
                        asChild
                        size="lg"
                        className="rounded-full shadow-sm transition-transform hover:scale-105"
                    >
                        <Link href="/subscribe">
                            Get started with a subscription
                        </Link>
                    </Button>
                )}
            </div>
        </div>
    );
}

const STATUS_COLORS: Record<string, string> = {
    active: 'bg-emerald-500',
    deploying: 'bg-amber-500',
    pending: 'bg-amber-500',
    paused: 'bg-neutral-400',
    error: 'bg-red-500',
};

function AgentList({ agents }: { agents: Agent[] }) {
    return (
        <div className="mt-4">
            <p className="text-sm text-muted-foreground">
                {agents.length} {agents.length === 1 ? 'agent' : 'agents'}
            </p>

            <div className="mt-5 grid grid-cols-1 gap-x-5 gap-y-10 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                {agents.map((agent) => (
                    <Link
                        key={agent.id}
                        href={`/agents/${agent.id}`}
                        className="group flex flex-col items-center transition-transform duration-500 hover:rotate-[1.5deg]"
                        style={{ transformOrigin: 'top center' }}
                    >
                        {/* Lanyard strap */}
                        <div className="h-8 w-[14px] rounded-[2px] bg-gradient-to-b from-muted/60 via-muted to-muted-foreground/15" />
                        {/* Metal clip */}
                        <div className="-mt-0.5 mb-[-2px] h-[14px] w-[18px] rounded-b-[5px] border-2 border-t-0 border-muted-foreground/20 bg-gradient-to-b from-muted/80 to-muted/30" />

                        {/* Badge card */}
                        <div className="w-full overflow-hidden rounded-xl shadow-sm transition-shadow duration-500 group-hover:shadow-md dark:shadow-none dark:ring-1 dark:ring-border">
                            {/* Header */}
                            <div className="bg-muted/50 px-4 pt-5 pb-4 text-center dark:bg-muted/30">
                                <AgentAvatar
                                    agent={agent}
                                    className="mx-auto mb-3 size-14 shrink-0 text-base ring-2 ring-background"
                                />
                                <h3 className="truncate text-sm font-bold">
                                    {agent.name}
                                </h3>
                                <p className="mt-0.5 truncate text-[11px] text-muted-foreground">
                                    {agent.role?.replace(/_/g, ' ') ??
                                        'AI Team Member'}
                                </p>
                                {agent.agent_mode && (
                                    <span className="mt-1 inline-block rounded-full bg-primary/10 px-2 py-0.5 text-[9px] font-medium text-primary">
                                        {agent.agent_mode === 'workforce' ? 'Task Agent' : 'Chat Agent'}
                                    </span>
                                )}
                            </div>

                            {/* Body */}
                            <div className="border-t bg-card px-4 py-4 text-center">
                                {/* Status + Harness */}
                                <div className="flex items-center justify-center gap-1.5">
                                    <span
                                        className={`h-1.5 w-1.5 rounded-full ${STATUS_COLORS[agent.status] ?? 'bg-neutral-400'}`}
                                    />
                                    <span className="text-[11px] font-medium text-muted-foreground capitalize">
                                        {agent.status}
                                    </span>
                                    {agent.harness_type && (
                                        <span className="rounded bg-muted px-1.5 py-0.5 text-[9px] font-medium text-muted-foreground">
                                            {agent.harness_type === 'hermes'
                                                ? 'Hermes'
                                                : 'OpenClaw'}
                                        </span>
                                    )}
                                </div>

                                {/* Channels */}
                                <div className="mt-3 flex items-center justify-center">
                                    <ChannelIcons agent={agent} />
                                </div>

                                {/* Last active */}
                                <p className="mt-2.5 text-[10px] text-muted-foreground/70">
                                    {relativeTime(agent.stats_last_active_at)}
                                </p>
                            </div>
                        </div>
                    </Link>
                ))}
            </div>
        </div>
    );
}

export default function AgentIndex({
    agents,
    server,
    currentPlan,
    hasBilling = true,
}: {
    agents: Agent[];
    server: Server | null;
    currentPlan?: string;
    hasBilling?: boolean;
}) {
    const hasAccess = !hasBilling || !!currentPlan;
    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'Agents',
            href: '/agents',
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Agents" />

            <div className="px-4 py-6 sm:px-6">
                <div className="flex items-center justify-between gap-4">
                    <Heading
                        variant="small"
                        title="Agents"
                        description="Manage your team's AI agents."
                    />

                    <div className="flex shrink-0 items-center gap-2">
                        {server && <ServerStatusIndicator server={server} />}
                        {hasAccess && (
                            <Button asChild size="sm">
                                <Link href="/agents/create">
                                    <Plus className="mr-1.5 size-3.5" />
                                    New Agent
                                </Link>
                            </Button>
                        )}
                    </div>
                </div>

                {agents.length === 0 ? (
                    <EmptyState subscribed={hasAccess} />
                ) : (
                    <>
                        {hasBilling && !currentPlan && (
                            <div className="mt-4 overflow-hidden rounded-xl border border-primary/20 bg-gradient-to-r from-primary/[0.06] via-primary/[0.03] to-transparent dark:from-primary/[0.10] dark:via-primary/[0.04]">
                                <div className="flex items-center justify-between px-6 py-5">
                                    <div className="flex items-center gap-4">
                                        <div className="flex size-10 items-center justify-center rounded-full bg-primary/10">
                                            <Bot className="size-5 text-primary" />
                                        </div>
                                        <div>
                                            <h3 className="text-sm font-semibold text-foreground">
                                                Ready to deploy your first AI
                                                employee?
                                            </h3>
                                            <p className="mt-0.5 text-sm text-muted-foreground">
                                                Subscribe to get a dedicated
                                                server, email inbox, browser,
                                                and channel access for your
                                                agents.
                                            </p>
                                        </div>
                                    </div>
                                    <Button
                                        asChild
                                        size="sm"
                                        className="shrink-0"
                                    >
                                        <Link href="/subscribe">
                                            Get started
                                        </Link>
                                    </Button>
                                </div>
                            </div>
                        )}
                        <AgentList agents={agents} />
                    </>
                )}
            </div>
        </AppLayout>
    );
}
