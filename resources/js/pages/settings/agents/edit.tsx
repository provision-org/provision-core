import { Transition } from '@headlessui/react';
import { Form, Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import type { Agent, BreadcrumbItem, Team } from '@/types';

const roles = [
    { value: 'bdr', label: 'BDR' },
    { value: 'executive_assistant', label: 'Executive Assistant' },
    { value: 'frontend_developer', label: 'Frontend Developer' },
    { value: 'researcher', label: 'Researcher' },
    { value: 'custom', label: 'Custom' },
];

const models = [
    { value: 'claude-opus-4-6', label: 'Claude Opus 4.6' },
    { value: 'claude-sonnet-4-5-20250929', label: 'Claude Sonnet 4.5' },
    { value: 'claude-haiku-4-5-20251001', label: 'Claude Haiku 4.5' },
    { value: 'gpt-4o', label: 'GPT-4o' },
    { value: 'gpt-4o-mini', label: 'GPT-4o Mini' },
];

export default function EditAgent({
    team,
    agent,
}: {
    team: Team;
    agent: Agent;
}) {
    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'Agents',
            href: `/settings/teams/${team.id}/agents`,
        },
        {
            title: agent.name,
            href: `/settings/teams/${team.id}/agents/${agent.id}`,
        },
        {
            title: 'Edit',
            href: `/settings/teams/${team.id}/agents/${agent.id}/edit`,
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Edit ${agent.name}`} />

            <h1 className="sr-only">Edit {agent.name}</h1>

            <SettingsLayout>
                <div className="space-y-6">
                    <Heading
                        variant="small"
                        title="Edit agent"
                        description="Update the agent's configuration."
                    />

                    <Form
                        action={`/settings/teams/${team.id}/agents/${agent.id}`}
                        method="patch"
                        options={{
                            preserveScroll: true,
                        }}
                        className="space-y-6"
                    >
                        {({ processing, recentlySuccessful, errors }) => (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="name">Agent name</Label>

                                    <Input
                                        id="name"
                                        className="mt-1 block w-full"
                                        defaultValue={agent.name}
                                        name="name"
                                        required
                                        placeholder="Agent name"
                                    />

                                    <InputError
                                        className="mt-2"
                                        message={errors.name}
                                    />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="role">Role</Label>

                                    <select
                                        id="role"
                                        name="role"
                                        defaultValue={agent.role}
                                        className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs transition-[color,box-shadow] outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                    >
                                        {roles.map((role) => (
                                            <option
                                                key={role.value}
                                                value={role.value}
                                            >
                                                {role.label}
                                            </option>
                                        ))}
                                    </select>

                                    <InputError
                                        className="mt-2"
                                        message={errors.role}
                                    />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="model_primary">
                                        Primary model
                                    </Label>

                                    <select
                                        id="model_primary"
                                        name="model_primary"
                                        defaultValue={agent.model_primary ?? ''}
                                        className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs transition-[color,box-shadow] outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                    >
                                        <option value="">
                                            Select a model...
                                        </option>
                                        {models.map((model) => (
                                            <option
                                                key={model.value}
                                                value={model.value}
                                            >
                                                {model.label}
                                            </option>
                                        ))}
                                    </select>

                                    <InputError
                                        className="mt-2"
                                        message={errors.model_primary}
                                    />
                                </div>

                                <Separator />

                                <div className="grid gap-2">
                                    <Label htmlFor="system_prompt">
                                        System prompt
                                    </Label>

                                    <textarea
                                        id="system_prompt"
                                        name="system_prompt"
                                        rows={4}
                                        defaultValue={agent.system_prompt ?? ''}
                                        placeholder="Describe what this agent should do..."
                                        className="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs transition-[color,box-shadow] outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                    />

                                    <InputError
                                        className="mt-2"
                                        message={errors.system_prompt}
                                    />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="identity">Identity</Label>

                                    <textarea
                                        id="identity"
                                        name="identity"
                                        rows={3}
                                        defaultValue={agent.identity ?? ''}
                                        placeholder="Define the agent's identity and personality..."
                                        className="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs transition-[color,box-shadow] outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                    />

                                    <InputError
                                        className="mt-2"
                                        message={errors.identity}
                                    />
                                </div>

                                <div className="flex items-center gap-4">
                                    <Button disabled={processing}>Save</Button>

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
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
