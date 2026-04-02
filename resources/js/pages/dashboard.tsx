import { Head, Link, usePage } from '@inertiajs/react';
import {
    Activity,
    AlertCircle,
    Bot,
    CheckCircle,
    Clock,
    Cpu,
    MessageSquare,
    ShieldAlert,
    Users,
    Zap,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import {
    Area,
    AreaChart,
    CartesianGrid,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import ActivityFeed from '@/components/agents/activity-feed';
import AgentAvatar from '@/components/agents/agent-avatar';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { formatTokens } from '@/lib/agents';
import { cn } from '@/lib/utils';
import type { Agent, AgentActivity, BreadcrumbItem, SharedData } from '@/types';

type DailyUsage = {
    date: string;
    tokens_input: number;
    tokens_output: number;
    messages: number;
    sessions: number;
};

type TokenStats = {
    total_input: number;
    total_output: number;
    total_sessions: number;
    total_messages: number;
};

type DashboardProps = {
    activities: AgentActivity[];
    agents: Agent[];
    taskCounts: {
        in_progress: number;
        in_review: number;
        blocked: number;
        total: number;
    };
    tokenStats: TokenStats;
    currentPlan?: string;
};

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: '/dashboard',
    },
];

function StatCard({
    title,
    value,
    icon: Icon,
    href,
}: {
    title: string;
    value: string | number;
    icon: React.ComponentType<{ className?: string }>;
    href?: string;
}) {
    const content = (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between pb-2">
                <CardTitle className="text-sm font-medium text-muted-foreground">
                    {title}
                </CardTitle>
                <Icon className="size-4 text-muted-foreground" />
            </CardHeader>
            <CardContent>
                <div className="text-2xl font-bold">{value}</div>
            </CardContent>
        </Card>
    );

    if (href) {
        return (
            <Link href={href} className="transition-opacity hover:opacity-80">
                {content}
            </Link>
        );
    }

    return content;
}

function AgentStatusSummary({ agents }: { agents: Agent[] }) {
    const active = agents.filter((a) => a.status === 'active').length;
    const deploying = agents.filter((a) => a.status === 'deploying').length;
    const errored = agents.filter((a) => a.status === 'error').length;

    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-sm font-medium">
                    Agent Status
                </CardTitle>
            </CardHeader>
            <CardContent>
                <div className="space-y-2">
                    <div className="flex items-center justify-between text-sm">
                        <div className="flex items-center gap-2">
                            <span className="size-2 rounded-full bg-green-500" />
                            <span className="text-muted-foreground">
                                Active
                            </span>
                        </div>
                        <span className="font-medium">{active}</span>
                    </div>
                    <div className="flex items-center justify-between text-sm">
                        <div className="flex items-center gap-2">
                            <span className="size-2 rounded-full bg-blue-500" />
                            <span className="text-muted-foreground">
                                Deploying
                            </span>
                        </div>
                        <span className="font-medium">{deploying}</span>
                    </div>
                    {errored > 0 && (
                        <div className="flex items-center justify-between text-sm">
                            <div className="flex items-center gap-2">
                                <span className="size-2 rounded-full bg-red-500" />
                                <span className="text-muted-foreground">
                                    Error
                                </span>
                            </div>
                            <span className="font-medium">{errored}</span>
                        </div>
                    )}
                </div>
            </CardContent>
        </Card>
    );
}

function formatChartTokens(value: number): string {
    if (value >= 1_000_000) return `${(value / 1_000_000).toFixed(1)}M`;
    if (value >= 1_000) return `${(value / 1_000).toFixed(1)}K`;
    return value.toString();
}

function formatDate(dateStr: string): string {
    const date = new Date(dateStr + 'T00:00:00');
    return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
}

function TeamUsageChart() {
    const [data, setData] = useState<DailyUsage[] | null>(null);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        fetch('/dashboard/usage-chart?days=30', {
            headers: { Accept: 'application/json' },
        })
            .then((res) => res.json())
            .then((json) => {
                setData(json);
                setLoading(false);
            })
            .catch(() => setLoading(false));
    }, []);

    if (loading) {
        return <div className="h-64 animate-pulse rounded-lg bg-muted" />;
    }

    if (!data || data.length === 0) {
        return (
            <div className="flex h-64 items-center justify-center text-sm text-muted-foreground">
                No usage data yet. Stats are synced every 2 minutes.
            </div>
        );
    }

    return (
        <ResponsiveContainer width="100%" height={256}>
            <AreaChart
                data={data}
                margin={{ top: 4, right: 4, left: -20, bottom: 0 }}
            >
                <defs>
                    <linearGradient
                        id="gradInputTeam"
                        x1="0"
                        y1="0"
                        x2="0"
                        y2="1"
                    >
                        <stop
                            offset="5%"
                            stopColor="#6366f1"
                            stopOpacity={0.5}
                        />
                        <stop
                            offset="95%"
                            stopColor="#6366f1"
                            stopOpacity={0.05}
                        />
                    </linearGradient>
                    <linearGradient
                        id="gradOutputTeam"
                        x1="0"
                        y1="0"
                        x2="0"
                        y2="1"
                    >
                        <stop
                            offset="5%"
                            stopColor="#06b6d4"
                            stopOpacity={0.5}
                        />
                        <stop
                            offset="95%"
                            stopColor="#06b6d4"
                            stopOpacity={0.05}
                        />
                    </linearGradient>
                </defs>
                <CartesianGrid
                    strokeDasharray="3 3"
                    className="stroke-border"
                />
                <XAxis
                    dataKey="date"
                    tickFormatter={formatDate}
                    tick={{ fontSize: 11 }}
                    className="fill-muted-foreground"
                    tickLine={false}
                    axisLine={false}
                />
                <YAxis
                    tickFormatter={formatChartTokens}
                    tick={{ fontSize: 11 }}
                    className="fill-muted-foreground"
                    tickLine={false}
                    axisLine={false}
                />
                <Tooltip
                    labelFormatter={(label) => formatDate(String(label))}
                    formatter={(value, name) => [
                        formatChartTokens(Number(value)),
                        name === 'tokens_input'
                            ? 'Input tokens'
                            : 'Output tokens',
                    ]}
                    contentStyle={{
                        backgroundColor: 'hsl(var(--popover))',
                        border: '1px solid hsl(var(--border))',
                        borderRadius: '0.5rem',
                        fontSize: '0.75rem',
                        color: 'hsl(var(--popover-foreground))',
                    }}
                />
                <Area
                    type="monotone"
                    dataKey="tokens_input"
                    stackId="1"
                    stroke="#6366f1"
                    fill="url(#gradInputTeam)"
                    strokeWidth={2}
                />
                <Area
                    type="monotone"
                    dataKey="tokens_output"
                    stackId="1"
                    stroke="#06b6d4"
                    fill="url(#gradOutputTeam)"
                    strokeWidth={2}
                />
            </AreaChart>
        </ResponsiveContainer>
    );
}

function AgentTokenBreakdown({ agents }: { agents: Agent[] }) {
    const sorted = [...agents].sort(
        (a, b) =>
            b.stats_tokens_input +
            b.stats_tokens_output -
            (a.stats_tokens_input + a.stats_tokens_output),
    );

    const totalTokens = agents.reduce(
        (sum, a) => sum + a.stats_tokens_input + a.stats_tokens_output,
        0,
    );

    if (totalTokens === 0) {
        return (
            <div className="py-6 text-center text-sm text-muted-foreground">
                No token usage recorded yet.
            </div>
        );
    }

    return (
        <div className="space-y-3">
            {sorted.map((agent) => {
                const agentTotal =
                    agent.stats_tokens_input + agent.stats_tokens_output;
                const percent =
                    totalTokens > 0 ? (agentTotal / totalTokens) * 100 : 0;

                return (
                    <Link
                        key={agent.id}
                        href={`/agents/${agent.id}`}
                        className="block rounded-lg p-2 transition-colors hover:bg-muted/50"
                    >
                        <div className="flex items-center justify-between">
                            <div className="flex items-center gap-2">
                                <AgentAvatar
                                    agent={agent}
                                    className="size-6 text-xs"
                                />
                                <span className="text-sm font-medium">
                                    {agent.name}
                                </span>
                            </div>
                            <span className="text-sm text-muted-foreground tabular-nums">
                                {formatTokens(agentTotal)}
                            </span>
                        </div>
                        <div className="mt-1.5 h-1.5 w-full overflow-hidden rounded-full bg-muted">
                            <div
                                className="h-full rounded-full bg-primary transition-all"
                                style={{ width: `${Math.min(percent, 100)}%` }}
                            />
                        </div>
                    </Link>
                );
            })}
        </div>
    );
}

export default function Dashboard({
    activities,
    agents,
    taskCounts,
    tokenStats,
    currentPlan,
}: DashboardProps) {
    const { auth, wallet } = usePage<SharedData>().props;
    const teamId = auth.user.current_team?.id ?? '';
    const showAutoTopUpNudge = wallet && !wallet.auto_topup_enabled;
    const lowBalance = wallet && wallet.balance_cents < 300;

    const hasAgents = agents.length > 0;
    const subscribed = !!currentPlan;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />
            <div className="px-4 py-6 sm:px-6">
                {!hasAgents ? (
                    <div className="relative mt-4 flex min-h-[70vh] flex-col items-center justify-center overflow-hidden rounded-2xl border border-dashed border-foreground/15 bg-background/50 px-6 py-12 backdrop-blur-sm sm:px-12">
                        <div className="pointer-events-none absolute inset-0 flex items-center justify-center opacity-30 mix-blend-screen">
                            <div className="h-[400px] w-[400px] rounded-full bg-primary/10 blur-[100px]" />
                        </div>

                        <div className="relative z-10 w-full max-w-3xl text-center">
                            <h2 className="font-editorial text-4xl leading-tight tracking-tight text-foreground">
                                Welcome to your AI Workforce
                            </h2>
                            <p className="mx-auto mt-4 max-w-xl text-[15px] leading-relaxed text-muted-foreground">
                                Once you deploy your first AI employee, this
                                dashboard will show real-time activity, token
                                usage, sessions, and task progress. How would
                                you like to start?
                            </p>

                            <div className="mt-12 grid gap-6 text-left sm:grid-cols-2">
                                <Link
                                    href={
                                        subscribed
                                            ? '/agents/create'
                                            : '/subscribe'
                                    }
                                    className="group relative flex flex-col rounded-2xl border border-foreground/10 bg-card p-6 shadow-sm transition-all hover:border-primary/30 hover:shadow-md"
                                >
                                    <div className="mb-4 inline-flex h-12 w-12 items-center justify-center rounded-xl bg-primary/10 text-primary transition-transform group-hover:scale-110">
                                        <Bot className="size-6" />
                                    </div>
                                    <h3 className="text-lg font-semibold text-foreground">
                                        Build from scratch
                                    </h3>
                                    <p className="mt-2 flex-1 text-sm leading-relaxed text-muted-foreground">
                                        Create a custom AI employee tailored to
                                        your specific workflows and tools.
                                    </p>
                                    <div className="mt-6 flex items-center text-sm font-medium text-primary">
                                        {subscribed
                                            ? 'Create agent'
                                            : 'Get started'}{' '}
                                        <Activity className="ml-1.5 size-4 transition-transform group-hover:translate-x-1" />
                                    </div>
                                </Link>

                                <Link
                                    href="/agents/library"
                                    className="group relative flex flex-col rounded-2xl border border-foreground/10 bg-card p-6 shadow-sm transition-all hover:border-primary/30 hover:shadow-md"
                                >
                                    <div className="mb-4 inline-flex h-12 w-12 items-center justify-center rounded-xl bg-primary/10 text-primary transition-transform group-hover:scale-110">
                                        <Users className="size-6" />
                                    </div>
                                    <h3 className="text-lg font-semibold text-foreground">
                                        Browse templates
                                    </h3>
                                    <p className="mt-2 flex-1 text-sm leading-relaxed text-muted-foreground">
                                        Hire pre-trained agents designed for
                                        specific roles like Marketing, Sales, or
                                        Support.
                                    </p>
                                    <div className="mt-6 flex items-center text-sm font-medium text-primary">
                                        Explore library{' '}
                                        <Activity className="ml-1.5 size-4 transition-transform group-hover:translate-x-1" />
                                    </div>
                                </Link>
                            </div>
                        </div>
                    </div>
                ) : (
                    <>
                        <h1 className="text-lg font-semibold">
                            Mission Control
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Monitor your team&apos;s agent activity in
                            real-time.
                        </p>

                        {showAutoTopUpNudge && (
                            <Link
                                href="/billing"
                                className={cn(
                                    'mt-4 flex items-start gap-3 rounded-lg border p-4 transition-colors hover:bg-muted/50',
                                    lowBalance
                                        ? 'border-destructive/50 bg-destructive/5'
                                        : 'border-amber-500/30 bg-amber-500/5',
                                )}
                            >
                                <ShieldAlert
                                    className={cn(
                                        'mt-0.5 size-5 shrink-0',
                                        lowBalance
                                            ? 'text-destructive'
                                            : 'text-amber-500',
                                    )}
                                />
                                <div>
                                    <p className="text-sm font-medium">
                                        {lowBalance
                                            ? 'Credits running low'
                                            : 'Enable auto top-up to keep your agents running'}
                                    </p>
                                    <p className="mt-0.5 text-xs text-muted-foreground">
                                        {lowBalance
                                            ? 'Your agents will stop responding when credits hit zero. Enable auto top-up in billing to prevent interruptions.'
                                            : 'Without auto top-up, your agents will stop mid-conversation when credits run out. Set it up in billing settings.'}
                                    </p>
                                </div>
                            </Link>
                        )}

                        {/* Top stat cards */}
                        <div className="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <StatCard
                                title="Total Tokens"
                                value={formatTokens(
                                    tokenStats.total_input +
                                        tokenStats.total_output,
                                )}
                                icon={Zap}
                            />
                            <StatCard
                                title="Sessions"
                                value={tokenStats.total_sessions}
                                icon={Cpu}
                            />
                            <StatCard
                                title="Messages"
                                value={tokenStats.total_messages}
                                icon={MessageSquare}
                            />
                            <StatCard
                                title="Total Agents"
                                value={agents.length}
                                icon={Users}
                                href="/agents"
                            />
                        </div>

                        {/* Task stats row */}
                        {taskCounts.total > 0 && (
                            <div className="mt-4 grid gap-4 sm:grid-cols-3">
                                <StatCard
                                    title="In Progress"
                                    value={taskCounts.in_progress}
                                    icon={Clock}
                                    href="/tasks"
                                />
                                <StatCard
                                    title="In Review"
                                    value={taskCounts.in_review}
                                    icon={CheckCircle}
                                    href="/tasks"
                                />
                                <StatCard
                                    title="Blocked"
                                    value={taskCounts.blocked}
                                    icon={AlertCircle}
                                    href="/tasks"
                                />
                            </div>
                        )}

                        {/* Usage chart + agent breakdown */}
                        <div className="mt-6 grid gap-6 lg:grid-cols-[1fr_320px]">
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-sm font-medium">
                                        Token Usage
                                    </CardTitle>
                                    <CardDescription>
                                        Daily token usage across all agents
                                        (last 30 days)
                                    </CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <TeamUsageChart />
                                </CardContent>
                            </Card>

                            <div className="space-y-6">
                                <Card>
                                    <CardHeader>
                                        <CardTitle className="text-sm font-medium">
                                            Usage by Agent
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        <AgentTokenBreakdown agents={agents} />
                                    </CardContent>
                                </Card>

                                <AgentStatusSummary agents={agents} />
                            </div>
                        </div>

                        {/* Activity feed */}
                        <div className="mt-6">
                            <Card>
                                <CardHeader className="flex flex-row items-center gap-2">
                                    <Activity className="size-4 text-muted-foreground" />
                                    <CardTitle className="text-sm font-medium">
                                        Activity Feed
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <ActivityFeed
                                        activities={activities}
                                        teamId={teamId}
                                    />
                                </CardContent>
                            </Card>
                        </div>
                    </>
                )}
            </div>
        </AppLayout>
    );
}
