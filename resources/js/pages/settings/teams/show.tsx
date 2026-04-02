import { Transition } from '@headlessui/react';
import { Form, Head, router } from '@inertiajs/react';
import DeleteConfirmDialog from '@/components/delete-confirm-dialog';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import type { BreadcrumbItem, Team, TeamInvitation, TeamMember } from '@/types';

export default function ShowTeam({
    team,
    members,
    invitations,
    isAdmin,
    isOwner,
}: {
    team: Team;
    members: TeamMember[];
    invitations: TeamInvitation[];
    isAdmin: boolean;
    isOwner: boolean;
}) {
    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'Team settings',
            href: `/settings/teams/${team.id}`,
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Team Settings" />

            <h1 className="sr-only">Team Settings</h1>

            <SettingsLayout>
                {/* Team Details */}
                <div className="space-y-6">
                    <Heading
                        variant="small"
                        title="Team details"
                        description="Your team's name and company information."
                    />

                    {isAdmin ? (
                        <Form
                            action={`/settings/teams/${team.id}`}
                            method="patch"
                            options={{
                                preserveScroll: true,
                            }}
                            className="space-y-6"
                        >
                            {({ processing, recentlySuccessful, errors }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="name">Team name</Label>
                                        <Input
                                            id="name"
                                            defaultValue={team.name}
                                            name="name"
                                            required
                                            placeholder="Team name"
                                        />
                                        <InputError message={errors.name} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="company_name">
                                            Company name
                                        </Label>
                                        <Input
                                            id="company_name"
                                            defaultValue={
                                                team.company_name ?? ''
                                            }
                                            name="company_name"
                                            placeholder="Acme Inc."
                                        />
                                        <InputError
                                            message={errors.company_name}
                                        />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="company_url">
                                            Company website
                                        </Label>
                                        <Input
                                            id="company_url"
                                            defaultValue={
                                                team.company_url ?? ''
                                            }
                                            name="company_url"
                                            placeholder="https://example.com"
                                        />
                                        <InputError
                                            message={errors.company_url}
                                        />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="company_description">
                                            What you do
                                        </Label>
                                        <textarea
                                            id="company_description"
                                            defaultValue={
                                                team.company_description ?? ''
                                            }
                                            name="company_description"
                                            rows={3}
                                            placeholder="Briefly describe what your company does..."
                                            className="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs transition-[color,box-shadow] outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                        />
                                        <InputError
                                            message={errors.company_description}
                                        />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="target_market">
                                            Target market
                                        </Label>
                                        <Input
                                            id="target_market"
                                            defaultValue={
                                                team.target_market ?? ''
                                            }
                                            name="target_market"
                                            placeholder="e.g. Small businesses, Enterprise SaaS"
                                        />
                                        <InputError
                                            message={errors.target_market}
                                        />
                                    </div>

                                    <div className="flex items-center gap-4">
                                        <Button disabled={processing}>
                                            Save
                                        </Button>

                                        <Transition
                                            show={recentlySuccessful}
                                            enter="transition ease-in-out"
                                            enterFrom="opacity-0"
                                            leave="transition ease-in-out"
                                            leaveTo="opacity-0"
                                        >
                                            <p className="text-sm text-neutral-600">
                                                Saved
                                            </p>
                                        </Transition>
                                    </div>
                                </>
                            )}
                        </Form>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            {team.name}
                        </p>
                    )}
                </div>

                <Separator />

                {/* Team Members */}
                <div className="space-y-6">
                    <Heading
                        variant="small"
                        title="Team members"
                        description="All of the people that are part of this team."
                    />

                    <div className="space-y-4">
                        {members.map((member) => (
                            <div
                                key={member.id}
                                className="flex items-center justify-between"
                            >
                                <div>
                                    <p className="text-sm font-medium">
                                        {member.name}
                                    </p>
                                    <p className="text-sm text-muted-foreground">
                                        {member.email}
                                    </p>
                                </div>

                                <div className="flex items-center gap-2">
                                    {team.user_id === member.id ? (
                                        <Badge variant="outline">Owner</Badge>
                                    ) : isAdmin ? (
                                        <>
                                            <Select
                                                defaultValue={member.pivot.role}
                                                onValueChange={(value) => {
                                                    router.put(
                                                        `/settings/teams/${team.id}/members/${member.id}`,
                                                        { role: value },
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    );
                                                }}
                                            >
                                                <SelectTrigger className="w-28">
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="admin">
                                                        Admin
                                                    </SelectItem>
                                                    <SelectItem value="member">
                                                        Member
                                                    </SelectItem>
                                                </SelectContent>
                                            </Select>

                                            <Button
                                                variant="destructive"
                                                size="sm"
                                                onClick={() => {
                                                    router.delete(
                                                        `/settings/teams/${team.id}/members/${member.id}`,
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    );
                                                }}
                                            >
                                                Remove
                                            </Button>
                                        </>
                                    ) : (
                                        <Badge variant="secondary">
                                            {member.pivot.role}
                                        </Badge>
                                    )}
                                </div>
                            </div>
                        ))}
                    </div>
                </div>

                <Separator />

                {/* Invite Team Members */}
                <div className="space-y-6">
                    <Heading
                        variant="small"
                        title="Invite a team member"
                        description="Invite a new team member by their email address."
                    />

                    <Form
                        action={`/settings/teams/${team.id}/invitations`}
                        method="post"
                        options={{
                            preserveScroll: true,
                        }}
                        resetOnSuccess
                        className="space-y-6"
                    >
                        {({ processing, recentlySuccessful, errors }) => (
                            <>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="grid gap-2">
                                        <Label htmlFor="email">
                                            Email address
                                        </Label>

                                        <Input
                                            id="email"
                                            type="email"
                                            name="email"
                                            required
                                            placeholder="email@example.com"
                                        />

                                        <InputError
                                            className="mt-2"
                                            message={errors.email}
                                        />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="role">Role</Label>

                                        <select
                                            id="role"
                                            name="role"
                                            defaultValue="member"
                                            className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs transition-[color,box-shadow] outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                        >
                                            <option value="admin">Admin</option>
                                            <option value="member">
                                                Member
                                            </option>
                                        </select>

                                        <InputError
                                            className="mt-2"
                                            message={errors.role}
                                        />
                                    </div>
                                </div>

                                <div className="flex items-center gap-4">
                                    <Button disabled={processing}>
                                        Send Invitation
                                    </Button>

                                    <Transition
                                        show={recentlySuccessful}
                                        enter="transition ease-in-out"
                                        enterFrom="opacity-0"
                                        leave="transition ease-in-out"
                                        leaveTo="opacity-0"
                                    >
                                        <p className="text-sm text-neutral-600">
                                            Invitation sent
                                        </p>
                                    </Transition>
                                </div>
                            </>
                        )}
                    </Form>
                </div>

                {/* Pending Invitations */}
                {invitations.length > 0 && (
                    <>
                        <Separator />

                        <div className="space-y-6">
                            <Heading
                                variant="small"
                                title="Pending invitations"
                                description="People who have been invited to the team but have not yet accepted."
                            />

                            <div className="space-y-4">
                                {invitations.map((invitation) => (
                                    <div
                                        key={invitation.id}
                                        className="flex items-center justify-between"
                                    >
                                        <div>
                                            <p className="text-sm font-medium">
                                                {invitation.email}
                                            </p>
                                            <p className="text-sm text-muted-foreground">
                                                {invitation.role}
                                            </p>
                                        </div>

                                        {isAdmin && (
                                            <Button
                                                variant="destructive"
                                                size="sm"
                                                onClick={() => {
                                                    router.delete(
                                                        `/team-invitations/${invitation.id}`,
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    );
                                                }}
                                            >
                                                Cancel
                                            </Button>
                                        )}
                                    </div>
                                ))}
                            </div>
                        </div>
                    </>
                )}

                {/* Danger Zone: Delete Team */}
                {isOwner && !team.personal_team && (
                    <>
                        <Separator />

                        <div className="space-y-6">
                            <Heading
                                variant="small"
                                title="Delete team"
                                description="Permanently delete this team."
                            />

                            <p className="text-sm text-muted-foreground">
                                Once a team is deleted, all of its resources and
                                data will be permanently deleted.
                            </p>

                            <DeleteConfirmDialog
                                name={team.name}
                                label="team"
                                onConfirm={() =>
                                    router.delete(`/settings/teams/${team.id}`)
                                }
                            />
                        </div>
                    </>
                )}
            </SettingsLayout>
        </AppLayout>
    );
}
