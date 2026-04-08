import { Head, router, useForm } from '@inertiajs/react';
import { Pause, Play, Plus, RefreshCw, Trash2 } from 'lucide-react';
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
import type { Agent, BreadcrumbItem, Routine } from '@/types';

const CRON_PRESETS: { label: string; value: string }[] = [
    { label: 'Every hour', value: '0 * * * *' },
    { label: 'Every 6 hours', value: '0 */6 * * *' },
    { label: 'Daily at midnight', value: '0 0 * * *' },
    { label: 'Daily at 9 AM', value: '0 9 * * *' },
    { label: 'Every Monday at 9 AM', value: '0 9 * * 1' },
    { label: 'Every weekday at 9 AM', value: '0 9 * * 1-5' },
    { label: 'First of every month', value: '0 0 1 * *' },
];

const COMMON_TIMEZONES = [
    'UTC',
    'America/New_York',
    'America/Chicago',
    'America/Denver',
    'America/Los_Angeles',
    'Europe/London',
    'Europe/Berlin',
    'Europe/Paris',
    'Asia/Tokyo',
    'Asia/Shanghai',
    'Asia/Kolkata',
    'Australia/Sydney',
];

function humanCron(expr: string): string {
    const map: Record<string, string> = {
        '* * * * *': 'Every minute',
        '0 * * * *': 'Every hour',
        '0 */6 * * *': 'Every 6 hours',
        '0 0 * * *': 'Daily at midnight',
        '0 9 * * *': 'Daily at 9 AM',
        '0 9 * * 1': 'Every Monday at 9 AM',
        '0 9 * * 1-5': 'Weekdays at 9 AM',
        '0 0 1 * *': 'First of every month',
    };
    return map[expr] ?? expr;
}

function formatDate(dateStr: string | null): string {
    if (!dateStr) return '-';
    return new Date(dateStr).toLocaleString(undefined, {
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function CreateRoutineDialog({ agents }: { agents: Agent[] }) {
    const [open, setOpen] = useState(false);
    const form = useForm({
        title: '',
        description: '',
        agent_id: '',
        cron_expression: '0 9 * * *',
        timezone: 'UTC',
    });

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        form.post('/company/routines', {
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
                    Create Routine
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Create Routine</DialogTitle>
                    <DialogDescription>
                        Schedule a recurring task for an agent.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={handleSubmit} className="space-y-4">
                    <div className="space-y-2">
                        <Label htmlFor="routine-title">Title</Label>
                        <Input
                            id="routine-title"
                            value={form.data.title}
                            onChange={(e) =>
                                form.setData('title', e.target.value)
                            }
                            placeholder="e.g. Daily standup report"
                            required
                        />
                        {form.errors.title && (
                            <p className="text-xs text-red-500">
                                {form.errors.title}
                            </p>
                        )}
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="routine-desc">
                            Description{' '}
                            <span className="text-muted-foreground">
                                (optional)
                            </span>
                        </Label>
                        <Textarea
                            id="routine-desc"
                            value={form.data.description}
                            onChange={(e) =>
                                form.setData('description', e.target.value)
                            }
                            rows={3}
                            placeholder="What should the agent do each time this runs?"
                        />
                    </div>
                    <div className="space-y-2">
                        <Label>Agent</Label>
                        <Select
                            value={form.data.agent_id}
                            onValueChange={(v) => form.setData('agent_id', v)}
                        >
                            <SelectTrigger>
                                <SelectValue placeholder="Select an agent" />
                            </SelectTrigger>
                            <SelectContent>
                                {agents.map((a) => (
                                    <SelectItem key={a.id} value={a.id}>
                                        {a.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        {form.errors.agent_id && (
                            <p className="text-xs text-red-500">
                                {form.errors.agent_id}
                            </p>
                        )}
                    </div>
                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-2">
                            <Label>Schedule</Label>
                            <Select
                                value={form.data.cron_expression}
                                onValueChange={(v) =>
                                    form.setData('cron_expression', v)
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {CRON_PRESETS.map((p) => (
                                        <SelectItem
                                            key={p.value}
                                            value={p.value}
                                        >
                                            {p.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <Input
                                value={form.data.cron_expression}
                                onChange={(e) =>
                                    form.setData(
                                        'cron_expression',
                                        e.target.value,
                                    )
                                }
                                placeholder="* * * * *"
                                className="mt-1 font-mono text-xs"
                            />
                            {form.errors.cron_expression && (
                                <p className="text-xs text-red-500">
                                    {form.errors.cron_expression}
                                </p>
                            )}
                        </div>
                        <div className="space-y-2">
                            <Label>Timezone</Label>
                            <Select
                                value={form.data.timezone}
                                onValueChange={(v) =>
                                    form.setData('timezone', v)
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {COMMON_TIMEZONES.map((tz) => (
                                        <SelectItem key={tz} value={tz}>
                                            {tz.replace(/_/g, ' ')}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
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

function RoutineRow({ routine }: { routine: Routine }) {
    const [confirmDelete, setConfirmDelete] = useState(false);

    function handleToggle() {
        router.post(
            `/company/routines/${routine.id}/toggle`,
            {},
            {
                preserveScroll: true,
            },
        );
    }

    function handleDelete() {
        router.delete(`/company/routines/${routine.id}`, {
            preserveScroll: true,
        });
    }

    return (
        <div className="flex items-center gap-3 rounded-lg border px-4 py-3 transition-colors hover:bg-muted/30">
            <div className="min-w-0 flex-1">
                <div className="flex items-center gap-2">
                    <span className="truncate text-sm font-medium">
                        {routine.title}
                    </span>
                    <Badge
                        className={
                            routine.status === 'active'
                                ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300'
                                : 'bg-neutral-100 text-neutral-500 dark:bg-neutral-800 dark:text-neutral-400'
                        }
                    >
                        {routine.status}
                    </Badge>
                </div>
                {routine.description && (
                    <p className="mt-0.5 truncate text-xs text-muted-foreground">
                        {routine.description}
                    </p>
                )}
            </div>

            {routine.agent && (
                <div className="flex shrink-0 items-center gap-1.5">
                    <span className="flex size-5 items-center justify-center rounded-full bg-primary/10 text-[10px] font-semibold text-primary">
                        {routine.agent.name.charAt(0).toUpperCase()}
                    </span>
                    <span className="truncate text-xs text-muted-foreground">
                        {routine.agent.name}
                    </span>
                </div>
            )}

            <div className="flex shrink-0 flex-col items-end gap-0.5">
                <span className="rounded bg-muted px-1.5 py-0.5 font-mono text-[10px] text-muted-foreground">
                    {humanCron(routine.cron_expression)}
                </span>
                <span className="text-[10px] text-muted-foreground">
                    {routine.timezone}
                </span>
            </div>

            <div className="flex shrink-0 flex-col items-end gap-0.5 text-[10px] text-muted-foreground">
                <span>Last: {formatDate(routine.last_run_at)}</span>
                <span>Next: {formatDate(routine.next_run_at)}</span>
            </div>

            <div className="flex shrink-0 items-center gap-1">
                <Button
                    variant="ghost"
                    size="sm"
                    onClick={handleToggle}
                    title={
                        routine.status === 'active'
                            ? 'Pause routine'
                            : 'Activate routine'
                    }
                >
                    {routine.status === 'active' ? (
                        <Pause className="size-3.5" />
                    ) : (
                        <Play className="size-3.5" />
                    )}
                </Button>

                {confirmDelete ? (
                    <div className="flex items-center gap-1">
                        <Button
                            variant="destructive"
                            size="sm"
                            onClick={handleDelete}
                        >
                            Confirm
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => setConfirmDelete(false)}
                        >
                            Cancel
                        </Button>
                    </div>
                ) : (
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => setConfirmDelete(true)}
                        title="Delete routine"
                    >
                        <Trash2 className="size-3.5 text-red-500" />
                    </Button>
                )}
            </div>
        </div>
    );
}

export default function RoutinesIndex({
    routines,
    agents,
}: {
    routines: Routine[];
    agents: Agent[];
}) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Company', href: '/company/tasks' },
        { title: 'Routines', href: '/company/routines' },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Routines" />

            <div className="px-4 py-6 sm:px-6">
                <div className="flex items-center justify-between gap-4">
                    <Heading
                        variant="small"
                        title="Routines"
                        description="Schedule recurring tasks for your agents."
                    />
                    <CreateRoutineDialog agents={agents} />
                </div>

                {routines.length === 0 ? (
                    <div className="mt-8 flex flex-col items-center rounded-lg border border-dashed py-16 text-center">
                        <RefreshCw className="size-10 text-muted-foreground/40" />
                        <p className="mt-4 text-sm text-muted-foreground">
                            No routines yet. Create your first routine to
                            automate recurring tasks.
                        </p>
                    </div>
                ) : (
                    <div className="mt-6 space-y-2">
                        {routines.map((routine) => (
                            <RoutineRow key={routine.id} routine={routine} />
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
