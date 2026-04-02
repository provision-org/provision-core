import { Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import { Pagination } from '@/components/pagination';
import AdminLayout from '@/layouts/admin-layout';
import type { AdminServer, BreadcrumbItem, PaginatedData } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin/dashboard' },
    { title: 'Servers', href: '/admin/servers' },
];

export default function AdminServersIndex({
    servers,
}: {
    servers: PaginatedData<AdminServer>;
}) {
    return (
        <AdminLayout breadcrumbs={breadcrumbs}>
            <Head title="Servers - Admin" />

            <div className="px-4 py-6 sm:px-6">
                <Heading
                    variant="small"
                    title="Servers"
                    description="All Hetzner servers across the platform."
                />

                <div className="mt-6">
                    {servers.data.length === 0 ? (
                        <p className="py-12 text-center text-sm text-muted-foreground">
                            No servers yet.
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
                                                IP Address
                                            </th>
                                            <th className="px-4 py-2.5 font-medium">
                                                Status
                                            </th>
                                            <th className="px-4 py-2.5 font-medium">
                                                Region
                                            </th>
                                            <th className="px-4 py-2.5 font-medium">
                                                Type
                                            </th>
                                            <th className="px-4 py-2.5 font-medium">
                                                Agents
                                            </th>
                                            <th className="px-4 py-2.5 font-medium">
                                                Provisioned
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        {servers.data.map((server) => (
                                            <tr
                                                key={server.id}
                                                className="hover:bg-muted/30"
                                            >
                                                <td className="px-4 py-2.5 font-medium">
                                                    {server.name}
                                                </td>
                                                <td className="px-4 py-2.5">
                                                    {server.team && (
                                                        <span className="text-muted-foreground">
                                                            {server.team.name}
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="px-4 py-2.5 font-mono text-xs">
                                                    {server.ipv4_address || '-'}
                                                </td>
                                                <td className="px-4 py-2.5">
                                                    <ServerStatusDot
                                                        status={server.status}
                                                    />
                                                </td>
                                                <td className="px-4 py-2.5 text-muted-foreground">
                                                    {server.region}
                                                </td>
                                                <td className="px-4 py-2.5 text-muted-foreground">
                                                    {server.server_type}
                                                </td>
                                                <td className="px-4 py-2.5">
                                                    {server.agents_count}
                                                </td>
                                                <td className="px-4 py-2.5 text-muted-foreground">
                                                    {server.provisioned_at
                                                        ? new Date(
                                                              server.provisioned_at,
                                                          ).toLocaleDateString()
                                                        : '-'}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                            <Pagination data={servers} />
                        </>
                    )}
                </div>
            </div>
        </AdminLayout>
    );
}

function ServerStatusDot({ status }: { status: string }) {
    const colors: Record<string, string> = {
        provisioning: 'bg-yellow-500',
        setup_complete: 'bg-blue-500',
        running: 'bg-green-500',
        stopped: 'bg-gray-400',
        error: 'bg-red-500',
        destroying: 'bg-orange-500',
    };

    return (
        <span className="flex items-center gap-1.5 text-xs">
            <span
                className={`inline-block h-2 w-2 rounded-full ${colors[status] || 'bg-gray-400'}`}
            />
            {status}
        </span>
    );
}
