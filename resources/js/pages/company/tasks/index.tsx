import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    Filter,
    KanbanSquare,
    Plus,
} from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { useEcho } from '@/hooks/use-echo';
import AppLayout from '@/layouts/app-layout';
import type { SharedData } from '@/types';
import type { Agent, BreadcrumbItem, Goal, GovernanceTask } from '@/types';

type Column = {
    key: GovernanceTask['status'];
    label: string;
};

const COLUMNS: Column[] = [
    { key: 'todo', label: 'To Do' },
    { key: 'in_progress', label: 'In Progress' },
    { key: 'in_review', label: 'In Review' },
    { key: 'done', label: 'Done' },
];

const PRIORITY_COLORS: Record<string, string> = {
    low: 'bg-neutral-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-400',
    medium: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
    high: 'bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-300',
    urgent: 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
};

const STATUS_ORDER: GovernanceTask['status'][] = [
    'todo',
    'in_progress',
    'in_review',
    'done',
];

function TaskCard({ task }: { task: GovernanceTask }) {
    const statusIdx = STATUS_ORDER.indexOf(task.status);

    return (
        <Link
            href={`/company/tasks/${task.id}`}
            className="block rounded-lg border bg-card p-3 shadow-xs transition-shadow hover:shadow-sm"
        >
            <div className="mb-2 flex items-center gap-2">
                {task.identifier && (
                    <Badge variant="outline" className="font-mono text-[10px]">
                        {task.identifier}
                    </Badge>
                )}
                <Badge
                    className={`text-[10px] ${PRIORITY_COLORS[task.priority] ?? ''}`}
                >
                    {task.priority}
                </Badge>
            </div>

            <p className="mb-2 text-sm leading-snug font-medium">
                {task.title}
            </p>

            {task.assigned_agent && (
                <div className="mb-1 flex items-center gap-2">
                    <span className="flex size-5 shrink-0 items-center justify-center rounded-full bg-primary/10 text-[10px] font-semibold text-primary">
                        {task.assigned_agent.name.charAt(0).toUpperCase()}
                    </span>
                    <span className="truncate text-xs text-muted-foreground">
                        {task.assigned_agent.name}
                    </span>
                </div>
            )}
            {task.goal && (
                <p className="mb-2 truncate text-[11px] text-muted-foreground/70">
                    {task.goal.title}
                </p>
            )}

            <div className="flex items-center gap-1">
                {statusIdx > 0 && (
                    <Button
                        variant="ghost"
                        size="icon"
                        className="size-6"
                        onClick={(e) => {
                            e.preventDefault();
                            router.patch(`/company/tasks/${task.id}`, {
                                status: STATUS_ORDER[statusIdx - 1],
                            });
                        }}
                    >
                        <ArrowLeft className="size-3" />
                    </Button>
                )}
                {statusIdx < STATUS_ORDER.length - 1 && (
                    <Button
                        variant="ghost"
                        size="icon"
                        className="size-6"
                        onClick={(e) => {
                            e.preventDefault();
                            router.patch(`/company/tasks/${task.id}`, {
                                status: STATUS_ORDER[statusIdx + 1],
                            });
                        }}
                    >
                        <ArrowRight className="size-3" />
                    </Button>
                )}
            </div>
        </Link>
    );
}

function CreateTaskDialog({
    agents,
    goals,
}: {
    agents: Agent[];
    goals: Goal[];
}) {
    const [open, setOpen] = useState(false);
    const form = useForm({
        title: '',
        description: '',
        agent_id: '',
        goal_id: '',
        priority: 'medium' as string,
    });

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        form.post('/company/tasks', {
            onSuccess: () => {
                setOpen(false);
                form.reset();
            },
        });
    }

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm">
                    <Plus className="mr-1.5 size-3.5" />
                    Create Task
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Create Task</DialogTitle>
                    <DialogDescription>
                        Add a new task to the board.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={handleSubmit} className="space-y-4">
                    <div className="space-y-2">
                        <Label htmlFor="title">Title</Label>
                        <Input
                            id="title"
                            value={form.data.title}
                            onChange={(e) =>
                                form.setData('title', e.target.value)
                            }
                            required
                        />
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="description">Description</Label>
                        <Textarea
                            id="description"
                            value={form.data.description}
                            onChange={(e) =>
                                form.setData('description', e.target.value)
                            }
                            rows={3}
                        />
                    </div>
                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-2">
                            <Label>Assign Agent</Label>
                            <Select
                                value={form.data.agent_id}
                                onValueChange={(v) =>
                                    form.setData('agent_id', v)
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Select agent" />
                                </SelectTrigger>
                                <SelectContent>
                                    {agents
                                        .filter(
                                            (a) => a.agent_mode === 'workforce',
                                        )
                                        .map((a) => (
                                            <SelectItem key={a.id} value={a.id}>
                                                {a.name}
                                            </SelectItem>
                                        ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-2">
                            <Label>Goal</Label>
                            <Select
                                value={form.data.goal_id}
                                onValueChange={(v) =>
                                    form.setData('goal_id', v)
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Select goal" />
                                </SelectTrigger>
                                <SelectContent>
                                    {goals.map((g) => (
                                        <SelectItem key={g.id} value={g.id}>
                                            {g.title}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    </div>
                    <div className="space-y-2">
                        <Label>Priority</Label>
                        <Select
                            value={form.data.priority}
                            onValueChange={(v) => form.setData('priority', v)}
                        >
                            <SelectTrigger>
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="low">Low</SelectItem>
                                <SelectItem value="medium">Medium</SelectItem>
                                <SelectItem value="high">High</SelectItem>
                                <SelectItem value="urgent">Urgent</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <DialogFooter>
                        <Button type="submit" disabled={form.processing}>
                            Create
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function TasksIndex({
    tasks,
    filters,
    agents,
    goals = [],
}: {
    tasks: GovernanceTask[];
    filters: { agent_id?: string; priority?: string };
    agents: Agent[];
    goals?: Goal[];
}) {
    const { auth } = usePage<SharedData>().props;
    const teamId = auth.user.current_team_id;

    // Real-time task updates via Reverb
    useEcho(`team.${teamId}`, '.task.status_changed', () => {
        router.reload({ only: ['tasks'] });
    });

    const [agentFilter, setAgentFilter] = useState(filters.agent_id ?? '');
    const [priorityFilter, setPriorityFilter] = useState(
        filters.priority ?? '',
    );

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Company', href: '/company/tasks' },
        { title: 'Tasks', href: '/company/tasks' },
    ];

    function applyFilters(agent: string, priority: string) {
        const params: Record<string, string> = {};
        if (agent) {
            params.agent_id = agent;
        }
        if (priority) {
            params.priority = priority;
        }
        router.get('/company/tasks', params, { preserveState: true });
    }

    const hasAnyTasks = tasks.length > 0;

    const filtered = tasks.filter((t) => {
        if (agentFilter && t.agent_id !== agentFilter) {
            return false;
        }
        if (priorityFilter && t.priority !== priorityFilter) {
            return false;
        }
        return true;
    });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Task Board" />

            <div className="px-4 py-6 sm:px-6">
                <div className="flex items-center justify-between gap-4">
                    <Heading
                        variant="small"
                        title="Task Board"
                        description="Manage and track agent tasks across your team."
                    />
                    <CreateTaskDialog agents={agents} goals={goals} />
                </div>

                {/* Filter bar */}
                <div className="mt-4 flex flex-wrap items-center gap-3">
                    <Filter className="size-4 text-muted-foreground" />
                    <Select
                        value={agentFilter}
                        onValueChange={(v) => {
                            const val = v === 'all' ? '' : v;
                            setAgentFilter(val);
                            applyFilters(val, priorityFilter);
                        }}
                    >
                        <SelectTrigger className="w-[180px]">
                            <SelectValue placeholder="All agents" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All agents</SelectItem>
                            {agents.map((a) => (
                                <SelectItem key={a.id} value={a.id}>
                                    {a.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Select
                        value={priorityFilter}
                        onValueChange={(v) => {
                            const val = v === 'all' ? '' : v;
                            setPriorityFilter(val);
                            applyFilters(agentFilter, val);
                        }}
                    >
                        <SelectTrigger className="w-[150px]">
                            <SelectValue placeholder="All priorities" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All priorities</SelectItem>
                            <SelectItem value="low">Low</SelectItem>
                            <SelectItem value="medium">Medium</SelectItem>
                            <SelectItem value="high">High</SelectItem>
                            <SelectItem value="urgent">Urgent</SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                {/* Empty state */}
                {!hasAnyTasks ? (
                    <div className="mt-8 flex flex-col items-center rounded-lg border border-dashed py-16 text-center">
                        <KanbanSquare className="size-10 text-muted-foreground/40" />
                        <p className="mt-4 text-sm font-medium text-foreground">
                            No tasks yet
                        </p>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Create your first task to get your agents working.
                        </p>
                        <div className="mt-4">
                            <CreateTaskDialog agents={agents} goals={goals} />
                        </div>
                    </div>
                ) : (
                    /* Kanban columns */
                    <div className="mt-6 grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-4">
                        {COLUMNS.map((col) => {
                            const colTasks = filtered.filter(
                                (t) => t.status === col.key,
                            );
                            return (
                                <div key={col.key} className="min-w-0">
                                    <div className="mb-3 flex items-center justify-between">
                                        <h3 className="text-sm font-medium">
                                            {col.label}
                                        </h3>
                                        <span className="rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground">
                                            {colTasks.length}
                                        </span>
                                    </div>
                                    <div className="space-y-3">
                                        {colTasks.map((task) => (
                                            <TaskCard
                                                key={task.id}
                                                task={task}
                                            />
                                        ))}
                                        {colTasks.length === 0 && (
                                            <div className="rounded-lg border border-dashed py-8 text-center text-xs text-muted-foreground">
                                                No tasks
                                            </div>
                                        )}
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
