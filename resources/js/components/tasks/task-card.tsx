import AgentAvatar from '@/components/agents/agent-avatar';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import type { Task } from '@/types';

const priorityVariant: Record<
    string,
    'default' | 'secondary' | 'destructive' | 'outline'
> = {
    low: 'secondary',
    medium: 'default',
    high: 'destructive',
};

const priorityLabel: Record<string, string> = {
    low: 'Low',
    medium: 'Medium',
    high: 'High',
};

export default function TaskCard({
    task,
    onClick,
}: {
    task: Task;
    onClick: () => void;
}) {
    const firstNote = task.notes?.[0];

    return (
        <Card
            className="cursor-pointer gap-0 py-0 transition-colors hover:bg-accent/50"
            onClick={onClick}
        >
            <CardContent className="space-y-2 p-3">
                <div className="flex items-start justify-between gap-2">
                    <p className="text-sm leading-tight font-medium">
                        {task.title}
                    </p>
                    {task.priority !== 'none' && (
                        <Badge
                            variant={
                                priorityVariant[task.priority] ?? 'outline'
                            }
                        >
                            {priorityLabel[task.priority] ?? task.priority}
                        </Badge>
                    )}
                </div>

                {task.agent && (
                    <div className="flex items-center gap-1.5">
                        <AgentAvatar
                            agent={task.agent}
                            className="size-4 text-[8px]"
                        />
                        <span className="text-xs text-muted-foreground">
                            {task.agent.name}
                        </span>
                    </div>
                )}

                {firstNote && (
                    <p className="truncate text-xs text-muted-foreground">
                        {firstNote.body.slice(0, 80)}
                    </p>
                )}

                {task.tags && task.tags.length > 0 && (
                    <div className="flex flex-wrap gap-1">
                        {task.tags.map((tag) => (
                            <Badge
                                key={tag}
                                variant="outline"
                                className="text-xs"
                            >
                                {tag}
                            </Badge>
                        ))}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
