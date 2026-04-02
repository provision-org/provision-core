import { Transition } from '@headlessui/react';
import { Form, Head, router } from '@inertiajs/react';
import { useState } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import type { Agent, BreadcrumbItem, Team } from '@/types';

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

export default function ShowAgent({
    team,
    agent,
}: {
    team: Team;
    agent: Agent;
}) {
    const [configOpen, setConfigOpen] = useState(false);

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'Agents',
            href: `/settings/teams/${team.id}/agents`,
        },
        {
            title: agent.name,
            href: `/settings/teams/${team.id}/agents/${agent.id}`,
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={agent.name} />

            <h1 className="sr-only">{agent.name}</h1>

            <SettingsLayout>
                {/* Agent Details */}
                <div className="space-y-6">
                    <div className="flex items-center justify-between">
                        <Heading
                            variant="small"
                            title="Agent details"
                            description="View and manage this agent."
                        />
                        <StatusBadge status={agent.status} />
                    </div>

                    <div className="space-y-3">
                        <div className="flex items-center justify-between">
                            <span className="text-sm text-muted-foreground">
                                Name
                            </span>
                            <span className="text-sm font-medium">
                                {agent.name}
                            </span>
                        </div>
                        <div className="flex items-center justify-between">
                            <span className="text-sm text-muted-foreground">
                                Role
                            </span>
                            <span className="text-sm font-medium">
                                {roleLabels[agent.role] ?? agent.role}
                            </span>
                        </div>
                        <div className="flex items-center justify-between">
                            <span className="text-sm text-muted-foreground">
                                Primary model
                            </span>
                            <span className="text-sm font-medium">
                                {agent.model_primary ?? 'Not set'}
                            </span>
                        </div>
                        {agent.server && (
                            <div className="flex items-center justify-between">
                                <span className="text-sm text-muted-foreground">
                                    Server
                                </span>
                                <span className="text-sm font-medium">
                                    {agent.server.name} ({agent.server.status})
                                </span>
                            </div>
                        )}
                    </div>
                </div>

                <Separator />

                {/* Connections */}
                <div className="space-y-6">
                    <Heading
                        variant="small"
                        title="Connections"
                        description="Integrations configured for this agent."
                    />

                    <div className="space-y-3">
                        <div className="flex items-center justify-between">
                            <span className="text-sm text-muted-foreground">
                                Slack
                            </span>
                            {agent.slack_connection ? (
                                <Badge
                                    variant={
                                        agent.slack_connection.status ===
                                        'connected'
                                            ? 'default'
                                            : agent.slack_connection.status ===
                                                'disconnected'
                                              ? 'secondary'
                                              : 'destructive'
                                    }
                                >
                                    {agent.slack_connection.status}
                                </Badge>
                            ) : (
                                <span className="text-sm text-muted-foreground">
                                    Not configured
                                </span>
                            )}
                        </div>
                        <div className="flex items-center justify-between">
                            <span className="text-sm text-muted-foreground">
                                Email
                            </span>
                            {agent.email_connection ? (
                                <span className="text-sm font-medium">
                                    {agent.email_connection.email_address ??
                                        agent.email_connection.status}
                                </span>
                            ) : (
                                <span className="text-sm text-muted-foreground">
                                    Not configured
                                </span>
                            )}
                        </div>
                    </div>
                </div>

                <Separator />

                {/* Edit Agent */}
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
                                        placeholder="Define the agent's identity..."
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

                <Separator />

                {/* Config Snapshot */}
                <Collapsible open={configOpen} onOpenChange={setConfigOpen}>
                    <div className="space-y-4">
                        <div className="flex items-center justify-between">
                            <Heading
                                variant="small"
                                title="Configuration snapshot"
                                description="Raw agent configuration data."
                            />
                            <CollapsibleTrigger asChild>
                                <Button variant="ghost" size="sm">
                                    {configOpen ? 'Hide' : 'Show'}
                                </Button>
                            </CollapsibleTrigger>
                        </div>

                        <CollapsibleContent>
                            <pre className="max-h-64 overflow-auto rounded-md bg-muted p-4 text-xs">
                                {JSON.stringify(agent, null, 2)}
                            </pre>
                        </CollapsibleContent>
                    </div>
                </Collapsible>

                <Separator />

                {/* Delete Agent */}
                <div className="space-y-6">
                    <Heading
                        variant="small"
                        title="Delete agent"
                        description="Permanently delete this agent."
                    />

                    <p className="text-sm text-muted-foreground">
                        Once an agent is deleted, all of its configuration will
                        be permanently removed.
                    </p>

                    <Dialog>
                        <DialogTrigger asChild>
                            <Button variant="destructive">Delete Agent</Button>
                        </DialogTrigger>
                        <DialogContent>
                            <DialogTitle>
                                Are you sure you want to delete this agent?
                            </DialogTitle>
                            <DialogDescription>
                                Once deleted, all of the agent's configuration
                                will be permanently removed.
                            </DialogDescription>

                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button variant="secondary">Cancel</Button>
                                </DialogClose>

                                <Button
                                    variant="destructive"
                                    onClick={() => {
                                        router.delete(
                                            `/settings/teams/${team.id}/agents/${agent.id}`,
                                        );
                                    }}
                                >
                                    Delete Agent
                                </Button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
