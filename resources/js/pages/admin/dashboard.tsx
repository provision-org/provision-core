import { Head, Link } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AdminLayout from '@/layouts/admin-layout';
import type { AdminStats, AdminUser, BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin/dashboard' },
];

export default function AdminDashboard({
    stats,
    recentSignups,
}: {
    stats: AdminStats;
    recentSignups: AdminUser[];
}) {
    return (
        <AdminLayout breadcrumbs={breadcrumbs}>
            <Head title="Admin Dashboard" />

            <div className="px-4 py-6 sm:px-6">
                <h2 className="mb-6 text-xl font-semibold tracking-tight">
                    Dashboard
                </h2>

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Card className="gap-2 py-4">
                        <CardHeader className="px-4 py-0">
                            <p className="text-sm text-muted-foreground">
                                Users
                            </p>
                        </CardHeader>
                        <CardContent className="px-4 py-0">
                            <p className="text-2xl font-bold">
                                {stats.total_users}
                            </p>
                            <p className="mt-1 text-xs text-muted-foreground">
                                {stats.activated_users} activated
                                {stats.waitlisted_users > 0 && (
                                    <span>
                                        {' '}
                                        &middot; {stats.waitlisted_users}{' '}
                                        waitlisted
                                    </span>
                                )}
                            </p>
                        </CardContent>
                    </Card>

                    <Card className="gap-2 py-4">
                        <CardHeader className="px-4 py-0">
                            <p className="text-sm text-muted-foreground">
                                Teams
                            </p>
                        </CardHeader>
                        <CardContent className="px-4 py-0">
                            <p className="text-2xl font-bold">
                                {stats.total_teams}
                            </p>
                        </CardContent>
                    </Card>

                    <Card className="gap-2 py-4">
                        <CardHeader className="px-4 py-0">
                            <p className="text-sm text-muted-foreground">
                                Agents
                            </p>
                        </CardHeader>
                        <CardContent className="px-4 py-0">
                            <p className="text-2xl font-bold">
                                {stats.total_agents}
                            </p>
                            {Object.keys(stats.agents_by_status).length > 0 && (
                                <div className="mt-1 flex flex-wrap gap-1">
                                    {Object.entries(stats.agents_by_status).map(
                                        ([status, count]) => (
                                            <Badge
                                                key={status}
                                                variant="secondary"
                                                className="text-[10px]"
                                            >
                                                {count} {status}
                                            </Badge>
                                        ),
                                    )}
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    <Card className="gap-2 py-4">
                        <CardHeader className="px-4 py-0">
                            <p className="text-sm text-muted-foreground">
                                Servers
                            </p>
                        </CardHeader>
                        <CardContent className="px-4 py-0">
                            <p className="text-2xl font-bold">
                                {stats.total_servers}
                            </p>
                            {Object.keys(stats.servers_by_status).length >
                                0 && (
                                <div className="mt-1 flex flex-wrap gap-1">
                                    {Object.entries(
                                        stats.servers_by_status,
                                    ).map(([status, count]) => (
                                        <Badge
                                            key={status}
                                            variant="secondary"
                                            className="text-[10px]"
                                        >
                                            {count} {status}
                                        </Badge>
                                    ))}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>

                <div className="mt-8">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Recent Signups
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {recentSignups.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    No signups yet.
                                </p>
                            ) : (
                                <div className="divide-y">
                                    {recentSignups.map((user) => (
                                        <div
                                            key={user.id}
                                            className="flex items-center justify-between py-2.5 first:pt-0 last:pb-0"
                                        >
                                            <div className="min-w-0">
                                                <Link
                                                    href={`/admin/users/${user.id}`}
                                                    className="text-sm font-medium hover:underline"
                                                >
                                                    {user.name}
                                                </Link>
                                                <p className="truncate text-xs text-muted-foreground">
                                                    {user.email}
                                                </p>
                                            </div>
                                            <div className="flex shrink-0 items-center gap-2">
                                                <Badge
                                                    variant={
                                                        user.activated_at
                                                            ? 'default'
                                                            : 'secondary'
                                                    }
                                                >
                                                    {user.activated_at
                                                        ? 'Active'
                                                        : 'Waitlisted'}
                                                </Badge>
                                                <span className="text-xs text-muted-foreground">
                                                    {new Date(
                                                        user.created_at,
                                                    ).toLocaleDateString()}
                                                </span>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AdminLayout>
    );
}
