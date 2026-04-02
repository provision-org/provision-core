import { Head, Link, router } from '@inertiajs/react';
import Heading from '@/components/heading';
import { Pagination } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AdminLayout from '@/layouts/admin-layout';
import type { AdminUser, BreadcrumbItem, PaginatedData } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin/dashboard' },
    { title: 'Users', href: '/admin/users' },
];

export default function AdminUsersIndex({
    users,
}: {
    users: PaginatedData<AdminUser>;
}) {
    return (
        <AdminLayout breadcrumbs={breadcrumbs}>
            <Head title="Users - Admin" />

            <div className="px-4 py-6 sm:px-6">
                <Heading
                    variant="small"
                    title="Users"
                    description="All registered users across the platform."
                />

                <div className="mt-6">
                    {users.data.length === 0 ? (
                        <p className="py-12 text-center text-sm text-muted-foreground">
                            No users yet.
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
                                                Email
                                            </th>
                                            <th className="px-4 py-2.5 font-medium">
                                                Teams
                                            </th>
                                            <th className="px-4 py-2.5 font-medium">
                                                Status
                                            </th>
                                            <th className="px-4 py-2.5 font-medium">
                                                Signed Up
                                            </th>
                                            <th className="px-4 py-2.5 font-medium">
                                                Actions
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        {users.data.map((user) => (
                                            <tr
                                                key={user.id}
                                                className="hover:bg-muted/30"
                                            >
                                                <td className="px-4 py-2.5">
                                                    <Link
                                                        href={`/admin/users/${user.id}`}
                                                        className="font-medium hover:underline"
                                                    >
                                                        {user.name}
                                                    </Link>
                                                </td>
                                                <td className="px-4 py-2.5 text-muted-foreground">
                                                    {user.email}
                                                </td>
                                                <td className="px-4 py-2.5">
                                                    {user.teams_count}
                                                </td>
                                                <td className="px-4 py-2.5">
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
                                                </td>
                                                <td className="px-4 py-2.5 text-muted-foreground">
                                                    {new Date(
                                                        user.created_at,
                                                    ).toLocaleDateString()}
                                                </td>
                                                <td className="px-4 py-2.5">
                                                    {user.activated_at ? (
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            onClick={() =>
                                                                router.post(
                                                                    `/admin/users/${user.id}/deactivate`,
                                                                    {},
                                                                    {
                                                                        preserveScroll: true,
                                                                    },
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
                                                                    {
                                                                        preserveScroll: true,
                                                                    },
                                                                )
                                                            }
                                                        >
                                                            Activate
                                                        </Button>
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                            <Pagination data={users} />
                        </>
                    )}
                </div>
            </div>
        </AdminLayout>
    );
}
