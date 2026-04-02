import { router, useForm } from '@inertiajs/react';
import { X } from 'lucide-react';
import type { FormEvent } from 'react';
import AgentAvatar from '@/components/agents/agent-avatar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { relativeTime } from '@/lib/agents';
import type { Agent, Task, TaskPriority, TaskStatus } from '@/types';

const statusOptions: { value: TaskStatus; label: string }[] = [
    { value: 'inbox', label: 'Inbox' },
    { value: 'up_next', label: 'Up Next' },
    { value: 'in_progress', label: 'In Progress' },
    { value: 'in_review', label: 'In Review' },
    { value: 'done', label: 'Done' },
];

const priorityOptions: { value: TaskPriority; label: string }[] = [
    { value: 'none', label: 'None' },
    { value: 'low', label: 'Low' },
    { value: 'medium', label: 'Medium' },
    { value: 'high', label: 'High' },
];

export default function TaskDetailPanel({
    task,
    agents,
    onClose,
}: {
    task: Task;
    agents: Agent[];
    onClose: () => void;
}) {
    const noteForm = useForm({ body: '' });

    function handleStatusChange(value: string) {
        router.patch(
            `/tasks/${task.id}`,
            { status: value },
            { preserveScroll: true },
        );
    }

    function handlePriorityChange(value: string) {
        router.patch(
            `/tasks/${task.id}`,
            { priority: value },
            { preserveScroll: true },
        );
    }

    function handleAgentChange(value: string) {
        router.patch(
            `/tasks/${task.id}`,
            { agent_id: value === 'unassigned' ? null : value },
            { preserveScroll: true },
        );
    }

    function handleAddNote(e: FormEvent) {
        e.preventDefault();
        if (!noteForm.data.body.trim()) return;

        noteForm.post(`/tasks/${task.id}/notes`, {
            preserveScroll: true,
            onSuccess: () => noteForm.reset('body'),
        });
    }

    return (
        <div className="fixed inset-y-0 right-0 z-50 w-full max-w-md overflow-y-auto border-l bg-background shadow-lg">
            <div className="space-y-6 p-6">
                <div className="flex items-center justify-between">
                    <h2 className="text-lg font-semibold">{task.title}</h2>
                    <Button variant="ghost" size="icon" onClick={onClose}>
                        <X className="size-4" />
                    </Button>
                </div>

                <div className="grid grid-cols-3 gap-3">
                    <div className="space-y-1.5">
                        <Label className="text-xs text-muted-foreground">
                            Status
                        </Label>
                        <Select
                            value={task.status}
                            onValueChange={handleStatusChange}
                        >
                            <SelectTrigger className="h-8 text-xs">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {statusOptions.map((opt) => (
                                    <SelectItem
                                        key={opt.value}
                                        value={opt.value}
                                    >
                                        {opt.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    <div className="space-y-1.5">
                        <Label className="text-xs text-muted-foreground">
                            Priority
                        </Label>
                        <Select
                            value={task.priority}
                            onValueChange={handlePriorityChange}
                        >
                            <SelectTrigger className="h-8 text-xs">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {priorityOptions.map((opt) => (
                                    <SelectItem
                                        key={opt.value}
                                        value={opt.value}
                                    >
                                        {opt.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    <div className="space-y-1.5">
                        <Label className="text-xs text-muted-foreground">
                            Agent
                        </Label>
                        <Select
                            value={task.agent_id ?? 'unassigned'}
                            onValueChange={handleAgentChange}
                        >
                            <SelectTrigger className="h-8 text-xs">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="unassigned">
                                    Unassigned
                                </SelectItem>
                                {agents.map((agent) => (
                                    <SelectItem key={agent.id} value={agent.id}>
                                        {agent.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                </div>

                {task.agent && (
                    <div className="flex items-center gap-2">
                        <AgentAvatar
                            agent={task.agent}
                            className="size-6 text-[10px]"
                        />
                        <span className="text-sm">{task.agent.name}</span>
                    </div>
                )}

                {task.description && (
                    <p className="text-sm text-muted-foreground">
                        {task.description}
                    </p>
                )}

                {task.tags && task.tags.length > 0 && (
                    <div className="flex flex-wrap gap-1">
                        {task.tags.map((tag) => (
                            <span
                                key={tag}
                                className="rounded-md bg-muted px-2 py-0.5 text-xs"
                            >
                                {tag}
                            </span>
                        ))}
                    </div>
                )}

                <div>
                    <h3 className="text-sm font-medium">Notes</h3>
                    <div className="mt-2 space-y-3">
                        {task.notes && task.notes.length > 0 ? (
                            task.notes.map((note) => (
                                <div
                                    key={note.id}
                                    className="rounded-md bg-muted p-3"
                                >
                                    <div className="flex items-center justify-between text-xs text-muted-foreground">
                                        <span>
                                            {note.author_type === 'agent'
                                                ? 'Agent'
                                                : 'You'}
                                        </span>
                                        <span>
                                            {relativeTime(note.created_at)}
                                        </span>
                                    </div>
                                    <p className="mt-1 text-sm">{note.body}</p>
                                </div>
                            ))
                        ) : (
                            <p className="text-xs text-muted-foreground">
                                No notes yet
                            </p>
                        )}
                    </div>
                </div>

                <form onSubmit={handleAddNote} className="flex gap-2">
                    <Input
                        placeholder="Add a note..."
                        value={noteForm.data.body}
                        onChange={(e) =>
                            noteForm.setData('body', e.target.value)
                        }
                        className="h-8 text-sm"
                    />
                    <Button
                        type="submit"
                        size="sm"
                        disabled={
                            noteForm.processing || !noteForm.data.body.trim()
                        }
                    >
                        Add
                    </Button>
                </form>
            </div>
        </div>
    );
}
