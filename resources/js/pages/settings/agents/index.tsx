import { Head, Link } from '@inertiajs/react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import type { Agent, BreadcrumbItem, Server, Team } from '@/types';

const roleLabels: Record<string, string> = {
    bdr: 'BDR',
    executive_assistant: 'Executive Assistant',
    frontend_developer: 'Frontend Developer',
    researcher: 'Researcher',
    custom: 'Custom',
};

function StatusBadge({ status }: { status: string }) {
    const variant =
        status === 'active'
            ? 'default'
            : status === 'paused'
              ? 'secondary'
              : 'destructive';

    return <Badge variant={variant}>{status}</Badge>;
}

function ServerStatusIndicator({ server }: { server: Server }) {
    const colors: Record<string, string> = {
        provisioning: 'bg-yellow-500',
        setup_complete: 'bg-blue-500',
        running: 'bg-green-500',
        stopped: 'bg-neutral-400',
        error: 'bg-red-500',
        destroying: 'bg-red-400',
    };

    return (
        <div className="flex items-center gap-2 text-sm text-muted-foreground">
            <span
                className={`inline-block h-2 w-2 rounded-full ${colors[server.status] ?? 'bg-neutral-400'}`}
            />
            Server: {server.name} ({server.status})
        </div>
    );
}

export default function AgentIndex({
    team,
    agents,
    server,
}: {
    team: Team;
    agents: Agent[];
    server: Server | null;
}) {
    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'Agents',
            href: `/settings/teams/${team.id}/agents`,
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Agents" />

            <h1 className="sr-only">Agents</h1>

            <SettingsLayout>
                <div className="space-y-6">
                    <div className="flex items-center justify-between">
                        <Heading
                            variant="small"
                            title="Agents"
                            description="Manage your team's AI agents."
                        />

                        <Button asChild size="sm">
                            <Link
                                href={`/settings/teams/${team.id}/agents/create`}
                            >
                                Create Agent
                            </Link>
                        </Button>
                    </div>

                    {server && <ServerStatusIndicator server={server} />}
                </div>

                <Separator />

                {agents.length === 0 ? (
                    <div className="space-y-4 text-center">
                        <p className="text-sm text-muted-foreground">
                            No agents yet. Create your first AI agent to get
                            started.
                        </p>
                        <Button asChild>
                            <Link
                                href={`/settings/teams/${team.id}/agents/create`}
                            >
                                Create your first AI agent
                            </Link>
                        </Button>
                    </div>
                ) : (
                    <div className="space-y-4">
                        {agents.map((agent) => (
                            <div
                                key={agent.id}
                                className="flex items-center justify-between"
                            >
                                <div>
                                    <Link
                                        href={`/settings/teams/${team.id}/agents/${agent.id}`}
                                        className="text-sm font-medium hover:underline"
                                    >
                                        {agent.name}
                                    </Link>
                                    <p className="text-sm text-muted-foreground">
                                        {roleLabels[agent.role] ?? agent.role}
                                        {agent.model_primary &&
                                            ` \u00B7 ${agent.model_primary}`}
                                    </p>
                                </div>

                                <div className="flex items-center gap-2">
                                    {agent.slack_connection && (
                                        <Badge variant="outline">
                                            Slack:{' '}
                                            {agent.slack_connection.status}
                                        </Badge>
                                    )}
                                    <StatusBadge status={agent.status} />
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </SettingsLayout>
        </AppLayout>
    );
}
