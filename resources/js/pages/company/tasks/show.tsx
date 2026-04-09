import { Head, Link } from '@inertiajs/react';
import { ChevronRight, Clock, Cpu, FileText, ListTree } from 'lucide-react';
import Markdown from 'react-markdown';
import TaskCommentThread from '@/components/task-comment-thread';
import TaskWorkProducts from '@/components/task-work-products';
import { Badge } from '@/components/ui/badge';
import AppLayout from '@/layouts/app-layout';
import type {
    AuditEntry,
    BreadcrumbItem,
    GovernanceTask,
    UsageEvent,
} from '@/types';

const STATUS_COLORS: Record<string, string> = {
    backlog:
        'bg-neutral-100 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-300',
    todo: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
    in_progress:
        'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
    blocked: 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
    in_review:
        'bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300',
    done: 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300',
    cancelled:
        'bg-neutral-100 text-neutral-500 dark:bg-neutral-800 dark:text-neutral-400',
};

const PRIORITY_COLORS: Record<string, string> = {
    low: 'bg-neutral-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-400',
    medium: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
    high: 'bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-300',
    urgent: 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
};

function formatTokens(n: number): string {
    if (n >= 1_000_000) {
        return `${(n / 1_000_000).toFixed(1)}M`;
    }
    return n.toLocaleString();
}

function formatDate(d: string): string {
    return new Date(d).toLocaleString(undefined, {
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

export default function TaskShow({
    task,
    subTasks = [],
    goalAncestry = [],
    usageEvents = [],
    auditEntries = [],
}: {
    task: GovernanceTask;
    subTasks?: GovernanceTask[];
    goalAncestry?: { id: string; title: string }[];
    usageEvents?: UsageEvent[];
    auditEntries?: AuditEntry[];
}) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Company', href: '/company/tasks' },
        { title: 'Tasks', href: '/company/tasks' },
        { title: task.identifier, href: `/company/tasks/${task.id}` },
    ];

    const totalInput = usageEvents.reduce((s, e) => s + e.input_tokens, 0);
    const totalOutput = usageEvents.reduce((s, e) => s + e.output_tokens, 0);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${task.identifier} - ${task.title}`} />

            <div className="px-4 py-6 sm:px-6">
                {/* Header */}
                <div className="flex flex-wrap items-start gap-3">
                    <Badge variant="outline" className="font-mono">
                        {task.identifier}
                    </Badge>
                    <Badge className={STATUS_COLORS[task.status] ?? ''}>
                        {task.status.replace(/_/g, ' ')}
                    </Badge>
                    <Badge className={PRIORITY_COLORS[task.priority] ?? ''}>
                        {task.priority}
                    </Badge>
                </div>

                <h1 className="mt-3 text-xl font-semibold tracking-tight">
                    {task.title}
                </h1>

                {/* Meta */}
                <div className="mt-4 flex flex-wrap gap-x-6 gap-y-2 text-sm text-muted-foreground">
                    {task.assigned_agent && (
                        <span>
                            Assigned to{' '}
                            <Link
                                href={`/agents/${task.assigned_agent.id}`}
                                className="font-medium text-foreground hover:underline"
                            >
                                {task.assigned_agent.name}
                            </Link>
                        </span>
                    )}
                    {task.delegated_by && (
                        <span>
                            Delegated by{' '}
                            {(
                                task as GovernanceTask & {
                                    delegated_by_agent?: {
                                        id: string;
                                        name: string;
                                    };
                                }
                            ).delegated_by_agent ? (
                                <Link
                                    href={`/agents/${(task as GovernanceTask & { delegated_by_agent?: { id: string; name: string } }).delegated_by_agent!.id}`}
                                    className="font-medium text-foreground hover:underline"
                                >
                                    {
                                        (
                                            task as GovernanceTask & {
                                                delegated_by_agent?: {
                                                    id: string;
                                                    name: string;
                                                };
                                            }
                                        ).delegated_by_agent!.name
                                    }
                                </Link>
                            ) : (
                                <span className="font-mono text-xs">
                                    {task.delegated_by.slice(0, 8)}
                                </span>
                            )}
                        </span>
                    )}
                    {task.started_at && (
                        <span className="flex items-center gap-1">
                            <Clock className="size-3.5" />
                            Started {formatDate(task.started_at)}
                        </span>
                    )}
                    {task.completed_at && (
                        <span>Completed {formatDate(task.completed_at)}</span>
                    )}
                </div>

                {/* Goal breadcrumb */}
                {goalAncestry.length > 0 && (
                    <div className="mt-4 flex items-center gap-1 text-sm">
                        <span className="text-muted-foreground">Goal:</span>
                        {goalAncestry.map((g, i) => (
                            <span
                                key={g.id}
                                className="flex items-center gap-1"
                            >
                                {i > 0 && (
                                    <ChevronRight className="size-3 text-muted-foreground" />
                                )}
                                <Link
                                    href={`/company/goals`}
                                    className="hover:underline"
                                >
                                    {g.title}
                                </Link>
                            </span>
                        ))}
                    </div>
                )}

                {/* Description */}
                {task.description && (
                    <div className="mt-6">
                        <h2 className="mb-2 flex items-center gap-2 text-sm font-medium">
                            <FileText className="size-4" />
                            Description
                        </h2>
                        <p className="text-sm whitespace-pre-wrap text-muted-foreground">
                            {task.description}
                        </p>
                    </div>
                )}

                {/* Result summary */}
                {task.result_summary && (
                    <div className="mt-6">
                        <h2 className="mb-2 text-sm font-medium">
                            Result Summary
                        </h2>
                        <div className="prose prose-sm dark:prose-invert max-w-none rounded-lg border bg-muted/30 p-4">
                            <Markdown>{task.result_summary}</Markdown>
                        </div>
                    </div>
                )}

                {/* Work products */}
                {task.work_products && task.work_products.length > 0 && (
                    <TaskWorkProducts
                        workProducts={task.work_products}
                        taskId={task.id}
                    />
                )}

                {/* Comment thread */}
                <TaskCommentThread
                    notes={task.notes ?? []}
                    taskId={task.id}
                    taskStatus={task.status}
                />
                {/* Sub-tasks */}
                {subTasks.length > 0 && (
                    <div className="mt-6">
                        <h2 className="mb-3 flex items-center gap-2 text-sm font-medium">
                            <ListTree className="size-4" />
                            Sub-tasks ({subTasks.length})
                        </h2>
                        <div className="space-y-2">
                            {subTasks.map((st) => (
                                <Link
                                    key={st.id}
                                    href={`/company/tasks/${st.id}`}
                                    className="flex items-center gap-3 rounded-lg border px-4 py-3 transition-colors hover:bg-muted/50"
                                >
                                    <Badge
                                        variant="outline"
                                        className="font-mono text-[10px]"
                                    >
                                        {st.identifier}
                                    </Badge>
                                    <span className="flex-1 text-sm">
                                        {st.title}
                                    </span>
                                    <Badge
                                        className={`text-[10px] ${STATUS_COLORS[st.status] ?? ''}`}
                                    >
                                        {st.status.replace(/_/g, ' ')}
                                    </Badge>
                                </Link>
                            ))}
                        </div>
                    </div>
                )}

                {/* Token usage */}
                <div className="mt-6">
                    <h2 className="mb-3 flex items-center gap-2 text-sm font-medium">
                        <Cpu className="size-4" />
                        Token Usage
                    </h2>
                    <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                        <div className="rounded-lg border p-4">
                            <p className="text-xs text-muted-foreground">
                                Task Input
                            </p>
                            <p className="mt-1 text-lg font-semibold">
                                {formatTokens(task.tokens_input)}
                            </p>
                        </div>
                        <div className="rounded-lg border p-4">
                            <p className="text-xs text-muted-foreground">
                                Task Output
                            </p>
                            <p className="mt-1 text-lg font-semibold">
                                {formatTokens(task.tokens_output)}
                            </p>
                        </div>
                        <div className="rounded-lg border p-4">
                            <p className="text-xs text-muted-foreground">
                                Events Input
                            </p>
                            <p className="mt-1 text-lg font-semibold">
                                {formatTokens(totalInput)}
                            </p>
                        </div>
                        <div className="rounded-lg border p-4">
                            <p className="text-xs text-muted-foreground">
                                Events Output
                            </p>
                            <p className="mt-1 text-lg font-semibold">
                                {formatTokens(totalOutput)}
                            </p>
                        </div>
                    </div>
                </div>

                {/* Audit timeline */}
                {auditEntries.length > 0 && (
                    <div className="mt-6">
                        <h2 className="mb-3 text-sm font-medium">
                            Audit Timeline
                        </h2>
                        <div className="relative space-y-0 border-l border-border pl-6">
                            {auditEntries.map((entry) => (
                                <div
                                    key={entry.id}
                                    className="relative pb-6 last:pb-0"
                                >
                                    <div className="absolute top-1 -left-[25px] size-2 rounded-full bg-muted-foreground/40" />
                                    <p className="text-xs text-muted-foreground">
                                        {formatDate(entry.created_at)}
                                    </p>
                                    <p className="mt-0.5 text-sm">
                                        <Badge
                                            variant="outline"
                                            className="mr-2 text-[10px]"
                                        >
                                            {entry.actor_type}
                                        </Badge>
                                        <span className="font-medium">
                                            {entry.action
                                                .replace(/[._]/g, ' ')
                                                .replace(/\b\w/g, (c) =>
                                                    c.toUpperCase(),
                                                )}
                                        </span>
                                        {entry.target_type && (
                                            <span className="text-muted-foreground">
                                                {' '}
                                                on {entry.target_type}
                                            </span>
                                        )}
                                    </p>
                                </div>
                            ))}
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
