import { Transition } from '@headlessui/react';
import { Form, Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { useClipboard } from '@/hooks/use-clipboard';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import type { Agent, BreadcrumbItem, Team } from '@/types';

export default function SlackSetup({
    team,
    agent,
    manifestYaml,
}: {
    team: Team;
    agent: Agent;
    manifestYaml: string;
}) {
    const [copiedText, copy] = useClipboard();

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
            title: 'Slack Setup',
            href: `/settings/teams/${team.id}/agents/${agent.id}/slack-setup`,
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Slack Setup" />

            <h1 className="sr-only">Slack Setup</h1>

            <SettingsLayout>
                {/* Step 1: Manifest */}
                <div className="space-y-6">
                    <Heading
                        variant="small"
                        title="Step 1: Create a Slack App"
                        description="Copy this manifest and use it to create a new Slack app at api.slack.com."
                    />

                    <div className="relative">
                        <pre className="max-h-80 overflow-auto rounded-md bg-muted p-4 text-xs">
                            {manifestYaml}
                        </pre>
                        <Button
                            variant="secondary"
                            size="sm"
                            className="absolute top-2 right-2"
                            onClick={() => copy(manifestYaml)}
                        >
                            {copiedText === manifestYaml ? 'Copied' : 'Copy'}
                        </Button>
                    </div>
                </div>

                <Separator />

                {/* Step 2 & 3: Enter tokens and save */}
                <div className="space-y-6">
                    <Heading
                        variant="small"
                        title="Step 2: Enter your Slack tokens"
                        description="After creating the app, copy the Bot Token and App Token and paste them below."
                    />

                    <Form
                        action={`/settings/teams/${team.id}/agents/${agent.id}/slack`}
                        method="post"
                        options={{
                            preserveScroll: true,
                        }}
                        className="space-y-6"
                    >
                        {({ processing, recentlySuccessful, errors }) => (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="bot_token">
                                        Bot Token (xoxb-...)
                                    </Label>

                                    <Input
                                        id="bot_token"
                                        name="bot_token"
                                        type="password"
                                        required
                                        placeholder="xoxb-..."
                                    />

                                    <InputError
                                        className="mt-2"
                                        message={errors.bot_token}
                                    />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="app_token">
                                        App Token (xapp-...)
                                    </Label>

                                    <Input
                                        id="app_token"
                                        name="app_token"
                                        type="password"
                                        required
                                        placeholder="xapp-..."
                                    />

                                    <InputError
                                        className="mt-2"
                                        message={errors.app_token}
                                    />
                                </div>

                                <div className="flex items-center gap-4">
                                    <Button disabled={processing}>
                                        Save Slack Connection
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
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
