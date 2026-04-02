import { Head, router } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AdminLayout from '@/layouts/admin-layout';
import type { AdminUser, Agent, BreadcrumbItem, Team } from '@/types';

export default function AdminUserShow({
    user,
    teams,
    agents,
}: {
    user: AdminUser;
    teams: (Team & {
        agents_count: number;
        members_count: number;
        owner: { id: string; name: string };
        server?: { id: string; status: string } | null;
    })[];
    agents: (Pick<
        Agent,
        'id' | 'name' | 'role' | 'status' | 'team_id' | 'created_at'
    > & {
        team: { id: string; name: string };
    })[];
}) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Admin', href: '/admin/dashboard' },
        { title: 'Users', href: '/admin/users' },
        { title: user.name, href: `/admin/users/${user.id}` },
    ];

    return (
        <AdminLayout breadcrumbs={breadcrumbs}>
            <Head title={`${user.name} - Admin`} />

            <div className="px-4 py-6 sm:px-6">
                <div className="flex items-center justify-between gap-4">
                    <div>
                        <h2 className="text-xl font-semibold tracking-tight">
                            {user.name}
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            {user.email}
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        <Badge
                            variant={
                                user.activated_at ? 'default' : 'secondary'
                            }
                        >
                            {user.activated_at ? 'Active' : 'Waitlisted'}
                        </Badge>
                        {user.is_admin && (
                            <Badge variant="outline">Admin</Badge>
                        )}
                        {user.activated_at ? (
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={() =>
                                    router.post(
                                        `/admin/users/${user.id}/deactivate`,
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Deactivate
                            </Button>
                        ) : (
                            <Button
                                size="sm"
                                onClick={() =>
                                    router.post(
                                        `/admin/users/${user.id}/activate`,
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Activate
                            </Button>
                        )}
                    </div>
                </div>

                <div className="mt-6 grid gap-4 sm:grid-cols-3">
                    <InfoItem label="Pronouns" value={user.pronouns} />
                    <InfoItem label="Timezone" value={user.timezone} />
                    <InfoItem label="Company" value={user.company_name} />
                    <InfoItem label="Company URL" value={user.company_url} />
                    <InfoItem
                        label="Target Market"
                        value={user.target_market}
                    />
                    <InfoItem
                        label="Signed Up"
                        value={new Date(user.created_at).toLocaleDateString()}
                    />
                </div>

                <div className="mt-8 grid gap-6 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Teams ({teams.length})
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {teams.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    No teams.
                                </p>
                            ) : (
                                <div className="divide-y">
                                    {teams.map((team) => (
                                        <div
                                            key={team.id}
                                            className="flex items-center justify-between py-2.5 first:pt-0 last:pb-0"
                                        >
                                            <div className="min-w-0">
                                                <p className="text-sm font-medium">
                                                    {team.name}
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    {team.members_count} members
                                                    &middot; {team.agents_count}{' '}
                                                    agents
                                                </p>
                                            </div>
                                            <div className="flex items-center gap-2">
                                                {team.server && (
                                                    <StatusDot
                                                        status={
                                                            team.server.status
                                                        }
                                                    />
                                                )}
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Agents ({agents.length})
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {agents.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    No agents.
                                </p>
                            ) : (
                                <div className="divide-y">
                                    {agents.map((agent) => (
                                        <div
                                            key={agent.id}
                                            className="flex items-center justify-between py-2.5 first:pt-0 last:pb-0"
                                        >
                                            <div className="min-w-0">
                                                <p className="text-sm font-medium">
                                                    {agent.name}
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    {agent.team.name} &middot;{' '}
                                                    {agent.role}
                                                </p>
                                            </div>
                                            <AgentStatusBadge
                                                status={agent.status}
                                            />
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

function InfoItem({ label, value }: { label: string; value: string | null }) {
    return (
        <div>
            <p className="text-xs text-muted-foreground">{label}</p>
            <p className="text-sm">{value || '-'}</p>
        </div>
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

function StatusDot({ status }: { status: string }) {
    const colors: Record<string, string> = {
        provisioning: 'bg-yellow-500',
        setup_complete: 'bg-blue-500',
        running: 'bg-green-500',
        stopped: 'bg-gray-400',
        error: 'bg-red-500',
        destroying: 'bg-orange-500',
    };

    return (
        <span className="flex items-center gap-1.5 text-xs text-muted-foreground">
            <span
                className={`inline-block h-2 w-2 rounded-full ${colors[status] || 'bg-gray-400'}`}
            />
            {status}
        </span>
    );
}
