import { Transition } from '@headlessui/react';
import { Form, Head, Link, usePage } from '@inertiajs/react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import AppLayout from '@/layouts/app-layout';
import type { Agent, BreadcrumbItem, SharedData } from '@/types';

type AvailableModel = {
    value: string;
    label: string;
    provider: string;
};

export default function EditAgent({
    agent,
    availableModels,
}: {
    agent: Agent;
    availableModels: AvailableModel[];
}) {
    const { auth } = usePage<SharedData>().props;

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'Agents',
            href: '/agents',
        },
        {
            title: agent.name,
            href: `/agents/${agent.id}`,
        },
        {
            title: 'Edit',
            href: `/agents/${agent.id}/edit`,
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Edit ${agent.name}`} />

            <div className="mx-auto max-w-2xl px-4 py-6">
                <div className="space-y-6">
                    <Heading
                        variant="small"
                        title="Edit agent"
                        description="Update the agent's configuration."
                    />

                    <Form
                        action={`/agents/${agent.id}`}
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
                                    <Label htmlFor="model_primary">
                                        Primary model
                                    </Label>

                                    {availableModels.length > 0 ? (
                                        <select
                                            id="model_primary"
                                            name="model_primary"
                                            defaultValue={
                                                agent.model_primary ?? ''
                                            }
                                            className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs transition-[color,box-shadow] outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                        >
                                            <option value="">
                                                Select a model...
                                            </option>
                                            {availableModels.map((model) => (
                                                <option
                                                    key={model.value}
                                                    value={model.value}
                                                >
                                                    {model.label} (
                                                    {model.provider})
                                                </option>
                                            ))}
                                        </select>
                                    ) : (
                                        <p className="text-sm text-muted-foreground">
                                            No models available.{' '}
                                            <Link
                                                href={`/settings/teams/${auth.user.current_team_id}/api-keys`}
                                                className="underline"
                                            >
                                                Configure an API key
                                            </Link>{' '}
                                            to unlock models.
                                        </p>
                                    )}

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
            </div>
        </AppLayout>
    );
}
