import { Head, router } from '@inertiajs/react';
import { BarChart3 } from 'lucide-react';
import Heading from '@/components/heading';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem, Team } from '@/types';

type AgentUsage = {
    agent_id: string;
    agent_name: string;
    input_tokens: number;
    output_tokens: number;
};

type DailyUsage = {
    date: string;
    input_tokens: number;
    output_tokens: number;
};

function formatTokens(n: number): string {
    if (n >= 1_000_000) {
        return `${(n / 1_000_000).toFixed(1)}M`;
    }
    if (n >= 1_000) {
        return `${(n / 1_000).toFixed(1)}k`;
    }
    return String(n);
}

function StatCard({ label, value }: { label: string; value: string }) {
    return (
        <div className="rounded-xl border bg-card p-5 shadow-xs">
            <p className="text-xs font-medium text-muted-foreground">{label}</p>
            <p className="mt-2 text-2xl font-semibold tracking-tight">
                {value}
            </p>
        </div>
    );
}

function BarRow({
    label,
    input,
    output,
    maxTotal,
}: {
    label: string;
    input: number;
    output: number;
    maxTotal: number;
}) {
    const total = input + output;
    const pct = maxTotal > 0 ? (total / maxTotal) * 100 : 0;
    const inputPct = total > 0 ? (input / total) * 100 : 0;

    return (
        <div className="flex items-center gap-4">
            <span className="w-28 shrink-0 truncate text-sm">{label}</span>
            <div className="h-5 flex-1 overflow-hidden rounded bg-muted">
                <div
                    className="flex h-full overflow-hidden rounded"
                    style={{ width: `${Math.max(pct, 1)}%` }}
                >
                    <div
                        className="h-full bg-primary/70"
                        style={{ width: `${inputPct}%` }}
                    />
                    <div
                        className="h-full bg-primary/30"
                        style={{ width: `${100 - inputPct}%` }}
                    />
                </div>
            </div>
            <span className="w-20 shrink-0 text-right text-xs text-muted-foreground">
                {formatTokens(total)}
            </span>
        </div>
    );
}

export default function UsageIndex({
    totalInputTokens,
    totalOutputTokens,
    byAgent,
    daily,
    period,
}: {
    totalInputTokens: number;
    totalOutputTokens: number;
    byAgent: AgentUsage[];
    daily: DailyUsage[];
    period: string;
    team: Team;
}) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Governance', href: '/governance/tasks' },
        { title: 'Usage', href: '/governance/usage' },
    ];

    const totalTokens = totalInputTokens + totalOutputTokens;
    const maxDailyTotal = Math.max(
        ...daily.map((d) => d.input_tokens + d.output_tokens),
        1,
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Usage" />

            <div className="px-4 py-6 sm:px-6">
                <div className="flex items-center justify-between gap-4">
                    <Heading
                        variant="small"
                        title="Usage Overview"
                        description="Monitor token consumption across your team."
                    />
                    <Select
                        value={period}
                        onValueChange={(v) =>
                            router.get(
                                '/governance/usage',
                                { period: v },
                                { preserveState: true },
                            )
                        }
                    >
                        <SelectTrigger className="w-[140px]">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="7d">Last 7 days</SelectItem>
                            <SelectItem value="30d">Last 30 days</SelectItem>
                            <SelectItem value="90d">Last 90 days</SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                {/* Summary cards */}
                <div className="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <StatCard
                        label="Total Tokens"
                        value={formatTokens(totalTokens)}
                    />
                    <StatCard
                        label="Input Tokens"
                        value={formatTokens(totalInputTokens)}
                    />
                    <StatCard
                        label="Output Tokens"
                        value={formatTokens(totalOutputTokens)}
                    />
                </div>

                {/* Daily chart (simple bar visualization) */}
                {daily.length > 0 && (
                    <div className="mt-8">
                        <h2 className="mb-4 text-sm font-medium">
                            Daily Usage
                        </h2>
                        <div className="space-y-2">
                            {daily.map((d) => (
                                <BarRow
                                    key={d.date}
                                    label={new Date(d.date).toLocaleDateString(
                                        undefined,
                                        { month: 'short', day: 'numeric' },
                                    )}
                                    input={d.input_tokens}
                                    output={d.output_tokens}
                                    maxTotal={maxDailyTotal}
                                />
                            ))}
                        </div>
                        <div className="mt-3 flex items-center gap-4 text-xs text-muted-foreground">
                            <span className="flex items-center gap-1.5">
                                <span className="inline-block size-2.5 rounded bg-primary/70" />
                                Input
                            </span>
                            <span className="flex items-center gap-1.5">
                                <span className="inline-block size-2.5 rounded bg-primary/30" />
                                Output
                            </span>
                        </div>
                    </div>
                )}

                {/* Per-agent table */}
                {byAgent.length > 0 && (
                    <div className="mt-8">
                        <h2 className="mb-4 text-sm font-medium">
                            Per-Agent Breakdown
                        </h2>
                        <div className="overflow-hidden rounded-lg border">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b bg-muted/50">
                                        <th className="px-4 py-2.5 text-left font-medium">
                                            Agent
                                        </th>
                                        <th className="px-4 py-2.5 text-right font-medium">
                                            Input
                                        </th>
                                        <th className="px-4 py-2.5 text-right font-medium">
                                            Output
                                        </th>
                                        <th className="px-4 py-2.5 text-right font-medium">
                                            Total
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {byAgent.map((a) => (
                                        <tr
                                            key={a.agent_id}
                                            className="border-b last:border-0"
                                        >
                                            <td className="px-4 py-2.5">
                                                {a.agent_name}
                                            </td>
                                            <td className="px-4 py-2.5 text-right text-muted-foreground">
                                                {formatTokens(a.input_tokens)}
                                            </td>
                                            <td className="px-4 py-2.5 text-right text-muted-foreground">
                                                {formatTokens(a.output_tokens)}
                                            </td>
                                            <td className="px-4 py-2.5 text-right font-medium">
                                                {formatTokens(
                                                    a.input_tokens +
                                                        a.output_tokens,
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}

                {byAgent.length === 0 && daily.length === 0 && (
                    <div className="mt-8 flex flex-col items-center rounded-lg border border-dashed py-16 text-center">
                        <BarChart3 className="size-10 text-muted-foreground/40" />
                        <p className="mt-4 text-sm text-muted-foreground">
                            No usage data for this period.
                        </p>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
