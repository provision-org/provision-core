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
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';

type DailyUsage = {
    date: string;
    tokens_input: number;
    tokens_output: number;
    messages: number;
    sessions: number;
};

function formatTokens(value: number): string {
    if (value >= 1_000_000) return `${(value / 1_000_000).toFixed(1)}M`;
    if (value >= 1_000) return `${(value / 1_000).toFixed(1)}K`;
    return value.toString();
}

function formatDate(dateStr: string): string {
    const date = new Date(dateStr + 'T00:00:00');
    return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
}

function ChartContent({ data }: { data: DailyUsage[] }) {
    return (
        <ResponsiveContainer width="100%" height={256}>
            <AreaChart
                data={data}
                margin={{ top: 4, right: 4, left: -20, bottom: 0 }}
            >
                <defs>
                    <linearGradient id="gradInput" x1="0" y1="0" x2="0" y2="1">
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
                    <linearGradient id="gradOutput" x1="0" y1="0" x2="0" y2="1">
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
                    tickFormatter={formatTokens}
                    tick={{ fontSize: 11 }}
                    className="fill-muted-foreground"
                    tickLine={false}
                    axisLine={false}
                />
                <Tooltip
                    labelFormatter={(label) => formatDate(String(label))}
                    formatter={(value, name) => [
                        formatTokens(Number(value)),
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
                    fill="url(#gradInput)"
                    strokeWidth={2}
                />
                <Area
                    type="monotone"
                    dataKey="tokens_output"
                    stackId="1"
                    stroke="#06b6d4"
                    fill="url(#gradOutput)"
                    strokeWidth={2}
                />
            </AreaChart>
        </ResponsiveContainer>
    );
}

export default function UsageChart({
    agentId,
    embedded = false,
}: {
    agentId: string;
    embedded?: boolean;
}) {
    const [data, setData] = useState<DailyUsage[] | null>(null);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        fetch(`/agents/${agentId}/usage-chart?days=30`, {
            headers: { Accept: 'application/json' },
        })
            .then((res) => res.json())
            .then((json) => {
                setData(json);
                setLoading(false);
            })
            .catch(() => setLoading(false));
    }, [agentId]);

    if (embedded) {
        if (loading) {
            return <div className="h-64 animate-pulse rounded-lg bg-muted" />;
        }
        if (!data || data.length === 0) {
            return null;
        }
        return <ChartContent data={data} />;
    }

    if (loading) {
        return (
            <Card>
                <CardHeader>
                    <CardTitle>Token Usage</CardTitle>
                    <CardDescription>
                        Daily token usage over the last 30 days.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <div className="h-64 animate-pulse rounded-lg bg-muted" />
                </CardContent>
            </Card>
        );
    }

    if (!data || data.length === 0) {
        return null;
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle>Token Usage</CardTitle>
                <CardDescription>
                    Daily token usage over the last 30 days.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <ChartContent data={data} />
            </CardContent>
        </Card>
    );
}
