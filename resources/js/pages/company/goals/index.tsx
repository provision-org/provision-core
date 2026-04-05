import { Head, useForm } from '@inertiajs/react';
import { ChevronDown, ChevronRight, Plus, Target } from 'lucide-react';
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
import AppLayout from '@/layouts/app-layout';
import type { Agent, BreadcrumbItem, Goal, Team } from '@/types';

const STATUS_COLORS: Record<string, string> = {
    active: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
    achieved:
        'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
    abandoned:
        'bg-neutral-100 text-neutral-500 dark:bg-neutral-800 dark:text-neutral-400',
    paused: 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-300',
};

const PRIORITY_COLORS: Record<string, string> = {
    low: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
    medium: 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-300',
    high: 'bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-300',
    critical: 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
};

function GoalRow({ goal, depth = 0 }: { goal: Goal; depth?: number }) {
    const [expanded, setExpanded] = useState(true);
    const hasChildren = goal.children && goal.children.length > 0;

    return (
        <>
            <div
                className="flex items-center gap-3 rounded-lg border px-4 py-3 transition-colors hover:bg-muted/30"
                style={{ marginLeft: `${depth * 24}px` }}
            >
                {hasChildren ? (
                    <button
                        onClick={() => setExpanded(!expanded)}
                        className="flex size-5 items-center justify-center rounded text-muted-foreground hover:bg-muted"
                    >
                        {expanded ? (
                            <ChevronDown className="size-3.5" />
                        ) : (
                            <ChevronRight className="size-3.5" />
                        )}
                    </button>
                ) : (
                    <div className="size-5" />
                )}

                <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-2">
                        <span className="truncate text-sm font-medium">
                            {goal.title}
                        </span>
                        <Badge
                            className={`text-[10px] ${STATUS_COLORS[goal.status] ?? ''}`}
                        >
                            {goal.status}
                        </Badge>
                        <Badge
                            className={`text-[10px] ${PRIORITY_COLORS[goal.priority] ?? ''}`}
                        >
                            {goal.priority}
                        </Badge>
                    </div>
                    {goal.description && (
                        <p className="mt-0.5 truncate text-xs text-muted-foreground">
                            {goal.description}
                        </p>
                    )}
                </div>

                {/* Child/linked counts */}
                <div className="flex shrink-0 items-center gap-2">
                    {goal.children && goal.children.length > 0 && (
                        <span className="rounded bg-muted px-1.5 py-0.5 text-[10px] text-muted-foreground">
                            {goal.children.length} sub-goal
                            {goal.children.length !== 1 ? 's' : ''}
                        </span>
                    )}
                </div>

                {/* Progress bar with gradient */}
                <div className="flex w-36 shrink-0 items-center gap-2">
                    <div className="h-2 flex-1 rounded-full bg-muted">
                        <div
                            className={`h-2 rounded-full transition-all ${
                                goal.progress_pct >= 70
                                    ? 'bg-emerald-500'
                                    : goal.progress_pct >= 30
                                      ? 'bg-amber-500'
                                      : 'bg-red-500'
                            }`}
                            style={{
                                width: `${Math.max(goal.progress_pct, 2)}%`,
                            }}
                        />
                    </div>
                    <span className="w-8 text-right text-[11px] font-medium text-muted-foreground">
                        {goal.progress_pct}%
                    </span>
                </div>

                {goal.owner_agent && (
                    <div className="flex shrink-0 items-center gap-1.5">
                        <span className="flex size-5 items-center justify-center rounded-full bg-primary/10 text-[10px] font-semibold text-primary">
                            {goal.owner_agent.name.charAt(0).toUpperCase()}
                        </span>
                        <span className="truncate text-xs text-muted-foreground">
                            {goal.owner_agent.name}
                        </span>
                    </div>
                )}

                {goal.target_date && (
                    <span className="shrink-0 text-xs text-muted-foreground">
                        {new Date(goal.target_date).toLocaleDateString(
                            undefined,
                            { month: 'short', day: 'numeric' },
                        )}
                    </span>
                )}
            </div>

            {hasChildren &&
                expanded &&
                goal.children!.map((child) => (
                    <GoalRow key={child.id} goal={child} depth={depth + 1} />
                ))}
        </>
    );
}

function CreateGoalDialog({
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
        parent_id: '',
        owner_agent_id: '',
        priority: 'medium' as string,
        target_date: '',
    });

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        form.post('/company/goals', {
            onSuccess: () => {
                setOpen(false);
                form.reset();
            },
        });
    }

    // Flatten goals for parent selection
    function flattenGoals(
        gs: Goal[],
        depth = 0,
    ): { id: string; title: string; depth: number }[] {
        const result: { id: string; title: string; depth: number }[] = [];
        for (const g of gs) {
            result.push({ id: g.id, title: g.title, depth });
            if (g.children) {
                result.push(...flattenGoals(g.children, depth + 1));
            }
        }
        return result;
    }

    const flatGoals = flattenGoals(goals);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm">
                    <Plus className="mr-1.5 size-3.5" />
                    Create Goal
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Create Goal</DialogTitle>
                    <DialogDescription>
                        Define a goal for your team or a specific agent.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={handleSubmit} className="space-y-4">
                    <div className="space-y-2">
                        <Label htmlFor="goal-title">Title</Label>
                        <Input
                            id="goal-title"
                            value={form.data.title}
                            onChange={(e) =>
                                form.setData('title', e.target.value)
                            }
                            required
                        />
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="goal-desc">Description</Label>
                        <Textarea
                            id="goal-desc"
                            value={form.data.description}
                            onChange={(e) =>
                                form.setData('description', e.target.value)
                            }
                            rows={3}
                        />
                    </div>
                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-2">
                            <Label>Parent Goal</Label>
                            <Select
                                value={form.data.parent_id}
                                onValueChange={(v) =>
                                    form.setData(
                                        'parent_id',
                                        v === 'none' ? '' : v,
                                    )
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="None (top level)" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="none">
                                        None (top level)
                                    </SelectItem>
                                    {flatGoals.map((g) => (
                                        <SelectItem key={g.id} value={g.id}>
                                            {'  '.repeat(g.depth)}
                                            {g.title}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-2">
                            <Label>Owner Agent</Label>
                            <Select
                                value={form.data.owner_agent_id}
                                onValueChange={(v) =>
                                    form.setData(
                                        'owner_agent_id',
                                        v === 'none' ? '' : v,
                                    )
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Unassigned" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="none">
                                        Unassigned
                                    </SelectItem>
                                    {agents.map((a) => (
                                        <SelectItem key={a.id} value={a.id}>
                                            {a.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    </div>
                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-2">
                            <Label>Priority</Label>
                            <Select
                                value={form.data.priority}
                                onValueChange={(v) =>
                                    form.setData('priority', v)
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="low">Low</SelectItem>
                                    <SelectItem value="medium">
                                        Medium
                                    </SelectItem>
                                    <SelectItem value="high">High</SelectItem>
                                    <SelectItem value="critical">
                                        Critical
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="target-date">Target Date</Label>
                            <Input
                                id="target-date"
                                type="date"
                                value={form.data.target_date}
                                onChange={(e) =>
                                    form.setData('target_date', e.target.value)
                                }
                            />
                        </div>
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

export default function GoalsIndex({
    goals,
    agents,
}: {
    goals: Goal[];
    agents: Agent[];
    team: Team;
}) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Company', href: '/company/tasks' },
        { title: 'Goals', href: '/company/goals' },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Goals" />

            <div className="px-4 py-6 sm:px-6">
                <div className="flex items-center justify-between gap-4">
                    <Heading
                        variant="small"
                        title="Goals"
                        description="Organize objectives for your team and agents."
                    />
                    <CreateGoalDialog agents={agents} goals={goals} />
                </div>

                {goals.length === 0 ? (
                    <div className="mt-8 flex flex-col items-center rounded-lg border border-dashed py-16 text-center">
                        <Target className="size-10 text-muted-foreground/40" />
                        <p className="mt-4 text-sm text-muted-foreground">
                            No goals yet. Create your first goal to get started.
                        </p>
                    </div>
                ) : (
                    <div className="mt-6 space-y-2">
                        {goals.map((goal) => (
                            <GoalRow key={goal.id} goal={goal} />
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
