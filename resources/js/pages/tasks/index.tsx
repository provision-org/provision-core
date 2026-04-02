import { Head } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import CreateTaskDialog from '@/components/tasks/create-task-dialog';
import TaskCard from '@/components/tasks/task-card';
import TaskDetailPanel from '@/components/tasks/task-detail-panel';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import type { Agent, BreadcrumbItem, Task, TaskStatus } from '@/types';

const columns: { status: TaskStatus; label: string }[] = [
    { status: 'inbox', label: 'Inbox' },
    { status: 'up_next', label: 'Up Next' },
    { status: 'in_progress', label: 'In Progress' },
    { status: 'in_review', label: 'In Review' },
    { status: 'done', label: 'Done' },
];

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Tasks', href: '/tasks' }];

function KanbanColumn({
    label,
    tasks,
    onSelectTask,
}: {
    label: string;
    tasks: Task[];
    onSelectTask: (task: Task) => void;
}) {
    return (
        <div className="flex min-w-[220px] flex-col">
            <div className="mb-3 flex items-center gap-2">
                <h3 className="text-sm font-medium">{label}</h3>
                <Badge variant="secondary" className="text-xs">
                    {tasks.length}
                </Badge>
            </div>
            <div className="flex-1 space-y-2">
                {tasks.length > 0 ? (
                    tasks.map((task) => (
                        <TaskCard
                            key={task.id}
                            task={task}
                            onClick={() => onSelectTask(task)}
                        />
                    ))
                ) : (
                    <div className="flex h-24 items-center justify-center rounded-lg border border-dashed text-xs text-muted-foreground">
                        No tasks
                    </div>
                )}
            </div>
        </div>
    );
}

export default function TasksIndex({
    tasks,
    agents,
}: {
    tasks: Task[];
    agents: Agent[];
}) {
    const [createOpen, setCreateOpen] = useState(false);
    const [selectedTask, setSelectedTask] = useState<Task | null>(null);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Tasks" />

            <div className="px-4 py-6 sm:px-6">
                <div className="flex items-center justify-between gap-4">
                    <Heading
                        variant="small"
                        title="Tasks"
                        description="Track and manage work across your team."
                    />
                    <Button size="sm" onClick={() => setCreateOpen(true)}>
                        <Plus className="mr-1.5 size-3.5" />
                        New Task
                    </Button>
                </div>

                <div className="mt-6 grid grid-cols-5 gap-4 overflow-x-auto">
                    {columns.map((col) => (
                        <KanbanColumn
                            key={col.status}
                            label={col.label}
                            tasks={tasks.filter((t) => t.status === col.status)}
                            onSelectTask={setSelectedTask}
                        />
                    ))}
                </div>
            </div>

            <CreateTaskDialog
                open={createOpen}
                onOpenChange={setCreateOpen}
                agents={agents}
            />

            {selectedTask && (
                <TaskDetailPanel
                    task={selectedTask}
                    agents={agents}
                    onClose={() => setSelectedTask(null)}
                />
            )}
        </AppLayout>
    );
}
