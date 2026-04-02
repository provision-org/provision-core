import { useState } from 'react';
import { useEcho } from '@/hooks/use-echo';
import { relativeTime } from '@/lib/agents';
import { cn } from '@/lib/utils';
import type { AgentActivity, ActivityType } from '@/types';

const typeColors: Record<ActivityType, string> = {
    message_received: 'bg-blue-500',
    message_sent: 'bg-blue-500',
    task_created: 'bg-purple-500',
    task_completed: 'bg-purple-500',
    task_blocked: 'bg-purple-500',
    session_started: 'bg-green-500',
    session_ended: 'bg-green-500',
    error: 'bg-red-500',
    agent_hired: 'bg-amber-500',
};

const MAX_ITEMS = 50;

export default function ActivityFeed({
    activities: initialActivities,
    teamId,
    agentId,
    className,
}: {
    activities: AgentActivity[];
    teamId: string;
    agentId?: string;
    className?: string;
}) {
    const [activities, setActivities] =
        useState<AgentActivity[]>(initialActivities);

    useEcho<AgentActivity>(`team.${teamId}`, '.agent.activity', (activity) => {
        if (agentId && activity.agent_id !== agentId) return;

        setActivities((prev) => [activity, ...prev].slice(0, MAX_ITEMS));
    });

    if (activities.length === 0) {
        return (
            <div
                className={cn(
                    'py-8 text-center text-sm text-muted-foreground',
                    className,
                )}
            >
                No activity yet
            </div>
        );
    }

    return (
        <div className={cn('space-y-1', className)}>
            {activities.map((activity) => (
                <div
                    key={activity.id}
                    className="flex items-center gap-3 rounded-md px-3 py-2 text-sm"
                >
                    <span
                        className={cn(
                            'size-2 shrink-0 rounded-full',
                            typeColors[activity.type] ?? 'bg-neutral-400',
                        )}
                    />
                    {!agentId && activity.agent_name && (
                        <span className="shrink-0 font-medium">
                            {activity.agent_name}
                        </span>
                    )}
                    <span className="flex-1 truncate text-muted-foreground">
                        {activity.summary}
                    </span>
                    <span className="shrink-0 text-xs text-muted-foreground">
                        {relativeTime(activity.created_at)}
                    </span>
                </div>
            ))}
        </div>
    );
}
