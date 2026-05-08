import { Head, router } from '@inertiajs/react';
import DeleteConfirmDialog from '@/components/delete-confirm-dialog';
import DeleteUser from '@/components/delete-user';
import Heading from '@/components/heading';
import { Separator } from '@/components/ui/separator';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Danger zone',
        href: '/settings/danger-zone',
    },
];

type DangerZoneTeam = {
    id: string;
    name: string;
    personal_team: boolean;
};

export default function DangerZone({
    team,
    canDeleteTeam,
}: {
    team: DangerZoneTeam | null;
    canDeleteTeam: boolean;
}) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Danger zone" />

            <h1 className="sr-only">Danger zone</h1>

            <SettingsLayout>
                <DeleteUser />

                {canDeleteTeam && team && (
                    <>
                        <Separator />

                        <div className="space-y-6">
                            <Heading
                                variant="small"
                                title="Delete team"
                                description="Permanently delete this team and all of its resources."
                            />

                            <div className="space-y-4 rounded-lg border border-red-100 bg-red-50 p-4 dark:border-red-200/10 dark:bg-red-700/10">
                                <div className="relative space-y-0.5 text-red-600 dark:text-red-100">
                                    <p className="font-medium">Warning</p>
                                    <p className="text-sm">
                                        Once {team.name} is deleted, all of its
                                        resources and data will be permanently
                                        removed. This cannot be undone.
                                    </p>
                                </div>

                                <DeleteConfirmDialog
                                    name={team.name}
                                    label="team"
                                    onConfirm={() =>
                                        router.delete(
                                            `/settings/teams/${team.id}`,
                                        )
                                    }
                                />
                            </div>
                        </div>
                    </>
                )}
            </SettingsLayout>
        </AppLayout>
    );
}
