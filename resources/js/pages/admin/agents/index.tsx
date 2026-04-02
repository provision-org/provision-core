import { Head, Link } from '@inertiajs/react';
import Heading from '@/components/heading';
import { Pagination } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import AdminLayout from '@/layouts/admin-layout';
import type { AdminAgent, BreadcrumbItem, PaginatedData } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin/dashboard' },
    { title: 'Agents', href: '/admin/agents' },
];

export default function AdminAgentsIndex({
    agents,
}: {
    agents: PaginatedData<AdminAgent>;
}) {
    return (
        <AdminLayout breadcrumbs={breadcrumbs}>
            <Head title="Agents - Admin" />

            <div className="px-4 py-6 sm:px-6">
                <Heading
                    variant="small"
                    title="Agents"
                    description="All agents across all teams."
                />

                <div className="mt-6">
                    {agents.data.length === 0 ? (
                        <p className="py-12 text-center text-sm text-muted-foreground">
                            No agents yet.
                        </p>
                    ) : (
                        <>
                            <div className="overflow-x-auto rounded-lg border">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b bg-muted/50 text-left">
                                            <th className="px-4 py-2.5 font-medium">
                                                Name
                                            </th>
                                            <th className="px-4 py-2.5 font-medium">
                                                Team
                                            </th>
                                            <th className="px-4 py-2.5 font-medium">
                                                Role
                                            </th>
                                            <th className="px-4 py-2.5 font-medium">
                                                Status
                                            </th>
                                            <th className="px-4 py-2.5 font-medium">
                                                Server
                                            </th>
                                            <th className="px-4 py-2.5 font-medium">
                                                Channels
                                            </th>
                                            <th className="px-4 py-2.5 font-medium">
                                                Created
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        {agents.data.map((agent) => (
                                            <tr
                                                key={agent.id}
                                                className="hover:bg-muted/30"
                                            >
                                                <td className="px-4 py-2.5 font-medium">
                                                    {agent.name}
                                                </td>
                                                <td className="px-4 py-2.5">
                                                    <Link
                                                        href={`/admin/users/${agent.team?.user_id}`}
                                                        className="text-muted-foreground hover:underline"
                                                    >
                                                        {agent.team?.name}
                                                    </Link>
                                                </td>
                                                <td className="px-4 py-2.5 text-muted-foreground">
                                                    {agent.role}
                                                </td>
                                                <td className="px-4 py-2.5">
                                                    <AgentStatusBadge
                                                        status={agent.status}
                                                    />
                                                </td>
                                                <td className="px-4 py-2.5 font-mono text-xs text-muted-foreground">
                                                    {agent.server
                                                        ?.ipv4_address || '-'}
                                                </td>
                                                <td className="px-4 py-2.5">
                                                    <ChannelBadges
                                                        agent={agent}
                                                    />
                                                </td>
                                                <td className="px-4 py-2.5 text-muted-foreground">
                                                    {new Date(
                                                        agent.created_at,
                                                    ).toLocaleDateString()}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                            <Pagination data={agents} />
                        </>
                    )}
                </div>
            </div>
        </AdminLayout>
    );
}

function AgentStatusBadge({ status }: { status: string }) {
    const variant =
        status === 'active'
            ? 'default'
            : status === 'paused'
              ? 'secondary'
              : status === 'error'
                ? 'destructive'
                : 'outline';

    return <Badge variant={variant}>{status}</Badge>;
}

function ChannelBadges({ agent }: { agent: AdminAgent }) {
    const channels: string[] = [];

    if (agent.slack_connection?.status === 'connected') channels.push('Slack');
    if (agent.email_connection?.status === 'connected') channels.push('Email');
    if (agent.telegram_connection?.status === 'connected')
        channels.push('Telegram');
    if (agent.discord_connection?.status === 'connected')
        channels.push('Discord');

    if (channels.length === 0) {
        return <span className="text-muted-foreground">-</span>;
    }

    return (
        <div className="flex flex-wrap gap-1">
            {channels.map((ch) => (
                <Badge key={ch} variant="secondary" className="text-[10px]">
                    {ch}
                </Badge>
            ))}
        </div>
    );
}
