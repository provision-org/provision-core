import { Head, Link, router } from '@inertiajs/react';
import { Filter, ScrollText } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import type { AuditEntry, BreadcrumbItem, Team } from '@/types';

const ACTOR_COLORS: Record<string, string> = {
    user: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
    agent: 'bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300',
    daemon: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
    system: 'bg-neutral-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300',
};

const POSITIVE_ACTIONS = [
    'created',
    'approved',
    'completed',
    'activated',
    'deployed',
    'connected',
];
const NEGATIVE_ACTIONS = [
    'rejected',
    'cancelled',
    'failed',
    'deleted',
    'error',
    'destroyed',
];

function getActionColor(action: string): string {
    const lower = action.toLowerCase();
    if (POSITIVE_ACTIONS.some((a) => lower.includes(a))) {
        return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300';
    }
    if (NEGATIVE_ACTIONS.some((a) => lower.includes(a))) {
        return 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300';
    }
    return 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300';
}

function humanizeAction(action: string): string {
    return action
        .replace(/[._]/g, ' ')
        .replace(/\b\w/g, (c) => c.toUpperCase());
}

function formatDate(d: string): string {
    return new Date(d).toLocaleString(undefined, {
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
    });
}

type PaginatedEntries = {
    data: AuditEntry[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    next_page_url: string | null;
    prev_page_url: string | null;
};

export default function AuditIndex({
    entries,
    filters,
}: {
    entries: PaginatedEntries | AuditEntry[];
    filters: { actor_type?: string; action?: string };
    team: Team;
}) {
    const [actorFilter, setActorFilter] = useState(filters.actor_type ?? '');
    const [actionFilter, setActionFilter] = useState(filters.action ?? '');

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Company', href: '/company/tasks' },
        { title: 'Audit Log', href: '/company/audit' },
    ];

    // Support both paginated and plain array
    const isPaginated = !Array.isArray(entries) && 'data' in entries;
    const entryList: AuditEntry[] = isPaginated
        ? (entries as PaginatedEntries).data
        : (entries as AuditEntry[]);
    const pagination = isPaginated ? (entries as PaginatedEntries) : null;

    function applyFilters(actor: string, action: string) {
        const params: Record<string, string> = {};
        if (actor) {
            params.actor_type = actor;
        }
        if (action) {
            params.action = action;
        }
        router.get('/company/audit', params, { preserveState: true });
    }

    // Collect unique actions for the filter
    const uniqueActions = [...new Set(entryList.map((e) => e.action))].sort();

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Audit Log" />

            <div className="px-4 py-6 sm:px-6">
                <Heading
                    variant="small"
                    title="Audit Log"
                    description="Track all actions and changes across your team."
                />

                {/* Filter bar */}
                <div className="mt-4 flex flex-wrap items-center gap-3">
                    <Filter className="size-4 text-muted-foreground" />
                    <Select
                        value={actorFilter}
                        onValueChange={(v) => {
                            const val = v === 'all' ? '' : v;
                            setActorFilter(val);
                            applyFilters(val, actionFilter);
                        }}
                    >
                        <SelectTrigger className="w-[150px]">
                            <SelectValue placeholder="All actors" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All actors</SelectItem>
                            <SelectItem value="user">User</SelectItem>
                            <SelectItem value="agent">Agent</SelectItem>
                            <SelectItem value="daemon">Daemon</SelectItem>
                            <SelectItem value="system">System</SelectItem>
                        </SelectContent>
                    </Select>
                    <Select
                        value={actionFilter}
                        onValueChange={(v) => {
                            const val = v === 'all' ? '' : v;
                            setActionFilter(val);
                            applyFilters(actorFilter, val);
                        }}
                    >
                        <SelectTrigger className="w-[180px]">
                            <SelectValue placeholder="All actions" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All actions</SelectItem>
                            {uniqueActions.map((a) => (
                                <SelectItem key={a} value={a}>
                                    {a}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                {/* Entry list */}
                {entryList.length === 0 ? (
                    <div className="mt-8 flex flex-col items-center rounded-lg border border-dashed py-16 text-center">
                        <ScrollText className="size-10 text-muted-foreground/40" />
                        <p className="mt-4 text-sm text-muted-foreground">
                            No audit entries found.
                        </p>
                    </div>
                ) : (
                    <div className="mt-4 space-y-1">
                        {entryList.map((entry) => {
                            const actorName =
                                (entry as AuditEntry & { actor_name?: string })
                                    .actor_name ?? null;

                            return (
                                <div
                                    key={entry.id}
                                    className="flex items-start gap-4 rounded-lg px-4 py-3 transition-colors hover:bg-muted/30"
                                >
                                    <span className="w-36 shrink-0 text-xs text-muted-foreground">
                                        {formatDate(entry.created_at)}
                                    </span>
                                    <Badge
                                        className={`shrink-0 text-[10px] ${ACTOR_COLORS[entry.actor_type] ?? ''}`}
                                    >
                                        {actorName ?? entry.actor_type}
                                    </Badge>
                                    <div className="min-w-0 flex-1">
                                        <Badge
                                            className={`text-[10px] ${getActionColor(entry.action)}`}
                                        >
                                            {humanizeAction(entry.action)}
                                        </Badge>
                                        {entry.target_type && (
                                            <span className="ml-2 text-sm text-muted-foreground">
                                                on {entry.target_type}
                                                {(entry as AuditEntry & { target_name?: string }).target_name ? (
                                                    <span className="ml-1 font-medium text-foreground">
                                                        {(entry as AuditEntry & { target_name?: string }).target_name}
                                                    </span>
                                                ) : entry.target_id ? (
                                                    <span className="ml-1 font-mono text-xs">
                                                        {entry.target_id.slice(0, 8)}
                                                    </span>
                                                ) : null}
                                            </span>
                                        )}
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                )}

                {/* Pagination */}
                {pagination && pagination.last_page > 1 && (
                    <div className="mt-6 flex items-center justify-between">
                        <p className="text-xs text-muted-foreground">
                            Page {pagination.current_page} of{' '}
                            {pagination.last_page} ({pagination.total} entries)
                        </p>
                        <div className="flex gap-2">
                            {pagination.prev_page_url && (
                                <Button variant="outline" size="sm" asChild>
                                    <Link href={pagination.prev_page_url}>
                                        Previous
                                    </Link>
                                </Button>
                            )}
                            {pagination.next_page_url && (
                                <Button variant="outline" size="sm" asChild>
                                    <Link href={pagination.next_page_url}>
                                        Next
                                    </Link>
                                </Button>
                            )}
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
