import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
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
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import type { Agent, TaskPriority } from '@/types';

export default function CreateTaskDialog({
    open,
    onOpenChange,
    agents,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    agents: Agent[];
}) {
    const form = useForm<{
        title: string;
        description: string;
        priority: TaskPriority;
        agent_id: string;
        tags: string;
    }>({
        title: '',
        description: '',
        priority: 'none',
        agent_id: '',
        tags: '',
    });

    function handleSubmit(e: FormEvent) {
        e.preventDefault();

        form.post('/tasks', {
            onSuccess: () => {
                form.reset();
                onOpenChange(false);
            },
        });
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Create Task</DialogTitle>
                    <DialogDescription>
                        Create a new task for your team.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={handleSubmit}>
                    <div className="space-y-4">
                        <div className="space-y-1.5">
                            <Label htmlFor="task-title">Title</Label>
                            <Input
                                id="task-title"
                                value={form.data.title}
                                onChange={(e) =>
                                    form.setData('title', e.target.value)
                                }
                                placeholder="What needs to be done?"
                            />
                            {form.errors.title && (
                                <p className="text-xs text-destructive">
                                    {form.errors.title}
                                </p>
                            )}
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor="task-description">
                                Description
                            </Label>
                            <textarea
                                id="task-description"
                                value={form.data.description}
                                onChange={(e) =>
                                    form.setData('description', e.target.value)
                                }
                                placeholder="Add more detail..."
                                rows={3}
                                className="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50"
                            />
                            {form.errors.description && (
                                <p className="text-xs text-destructive">
                                    {form.errors.description}
                                </p>
                            )}
                        </div>

                        <div className="space-y-1.5">
                            <Label>Priority</Label>
                            <ToggleGroup
                                type="single"
                                variant="outline"
                                value={form.data.priority}
                                onValueChange={(value) => {
                                    if (value)
                                        form.setData(
                                            'priority',
                                            value as TaskPriority,
                                        );
                                }}
                                className="justify-start"
                            >
                                <ToggleGroupItem
                                    value="none"
                                    className="text-xs"
                                >
                                    None
                                </ToggleGroupItem>
                                <ToggleGroupItem
                                    value="low"
                                    className="text-xs"
                                >
                                    Low
                                </ToggleGroupItem>
                                <ToggleGroupItem
                                    value="medium"
                                    className="text-xs"
                                >
                                    Medium
                                </ToggleGroupItem>
                                <ToggleGroupItem
                                    value="high"
                                    className="text-xs"
                                >
                                    High
                                </ToggleGroupItem>
                            </ToggleGroup>
                        </div>

                        <div className="space-y-1.5">
                            <Label>Assign to</Label>
                            <Select
                                value={form.data.agent_id || 'unassigned'}
                                onValueChange={(value) =>
                                    form.setData(
                                        'agent_id',
                                        value === 'unassigned' ? '' : value,
                                    )
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Unassigned" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="unassigned">
                                        Unassigned
                                    </SelectItem>
                                    {agents.map((agent) => (
                                        <SelectItem
                                            key={agent.id}
                                            value={agent.id}
                                        >
                                            {agent.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor="task-tags">Tags</Label>
                            <Input
                                id="task-tags"
                                value={form.data.tags}
                                onChange={(e) =>
                                    form.setData('tags', e.target.value)
                                }
                                placeholder="Comma-separated tags"
                            />
                        </div>
                    </div>

                    <DialogFooter className="mt-6">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            Create Task
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
