import { cn } from '@/lib/utils';
import type { Agent } from '@/types';

export default function AgentAvatar({
    agent,
    className,
}: {
    agent: Agent;
    className?: string;
}) {
    const initials = agent.name
        .split(/\s+/)
        .map((w) => w[0])
        .join('')
        .slice(0, 2)
        .toUpperCase();

    if (agent.avatar_path) {
        return (
            <img
                src={`/storage/${agent.avatar_path}`}
                alt={agent.name}
                className={cn('rounded-full object-cover', className)}
            />
        );
    }

    if (agent.emoji) {
        return (
            <div
                className={cn(
                    'flex items-center justify-center rounded-full bg-muted',
                    className,
                )}
            >
                <span className="text-lg">{agent.emoji}</span>
            </div>
        );
    }

    return (
        <div
            className={cn(
                'flex items-center justify-center rounded-full bg-muted font-semibold text-muted-foreground',
                className,
            )}
        >
            {initials}
        </div>
    );
}
