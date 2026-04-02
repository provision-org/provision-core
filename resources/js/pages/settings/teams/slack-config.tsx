import { Transition } from '@headlessui/react';
import { Form, Head, router } from '@inertiajs/react';
import { Eye, EyeOff } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import type { BreadcrumbItem, SlackConfigurationToken, Team } from '@/types';

function TokenInput({
    id,
    name,
    placeholder,
    required,
}: {
    id: string;
    name: string;
    placeholder: string;
    required?: boolean;
}) {
    const [visible, setVisible] = useState(false);

    return (
        <div className="relative">
            <Input
                id={id}
                name={name}
                type={visible ? 'text' : 'password'}
                required={required}
                placeholder={placeholder}
                className="pr-10"
            />
            <button
                type="button"
                onClick={() => setVisible(!visible)}
                className="absolute top-1/2 right-3 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                tabIndex={-1}
            >
                {visible ? (
                    <EyeOff className="size-4" />
                ) : (
                    <Eye className="size-4" />
                )}
            </button>
        </div>
    );
}

export default function SlackConfig({
    team,
    configToken,
}: {
    team: Team;
    configToken: SlackConfigurationToken | null;
}) {
    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'Team settings',
            href: `/settings/teams/${team.id}`,
        },
        {
            title: 'Slack',
            href: `/settings/teams/${team.id}/slack-config`,
        },
    ];

    const expiresAt = configToken?.expires_at
        ? new Date(configToken.expires_at)
        : null;
    const isExpired = expiresAt ? expiresAt < new Date() : false;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Slack Configuration" />

            <h1 className="sr-only">Slack Configuration</h1>

            <SettingsLayout>
                <div className="space-y-6">
                    <Heading
                        variant="small"
                        title="Slack Configuration Token"
                        description="Connect your Slack workspace to enable automated Slack app creation for agents."
                    />

                    {configToken ? (
                        <div className="space-y-4">
                            <div className="flex items-center gap-3">
                                <span className="text-sm font-medium">
                                    Status:
                                </span>
                                <Badge
                                    variant={
                                        isExpired ? 'destructive' : 'default'
                                    }
                                >
                                    {isExpired ? 'Expired' : 'Active'}
                                </Badge>
                                {expiresAt && !isExpired && (
                                    <span className="text-sm text-muted-foreground">
                                        Expires {expiresAt.toLocaleString()}
                                    </span>
                                )}
                            </div>

                            <div className="flex gap-2">
                                <Button
                                    variant="destructive"
                                    size="sm"
                                    onClick={() =>
                                        router.delete(
                                            `/settings/teams/${team.id}/slack-config`,
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    Remove Token
                                </Button>
                            </div>
                        </div>
                    ) : null}
                </div>

                <Separator />

                <div className="space-y-6">
                    <Heading
                        variant="small"
                        title={
                            configToken
                                ? 'Replace Configuration Token'
                                : 'Add Configuration Token'
                        }
                        description="Generate a Configuration Token from your Slack workspace settings."
                    />

                    <div className="space-y-2 rounded-md bg-muted p-4 text-sm">
                        <p className="font-medium">How to get your tokens:</p>
                        <ol className="list-inside list-decimal space-y-1 text-muted-foreground">
                            <li>
                                Go to{' '}
                                <a
                                    href="https://api.slack.com/apps"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="text-foreground underline"
                                >
                                    api.slack.com/apps
                                </a>
                            </li>
                            <li>
                                Scroll down to "Your App Configuration Tokens"
                            </li>
                            <li>
                                Click "Generate Token" and select your workspace
                            </li>
                            <li>
                                Copy the Access Token and Refresh Token below
                            </li>
                        </ol>
                    </div>

                    <Form
                        action={`/settings/teams/${team.id}/slack-config`}
                        method="post"
                        options={{ preserveScroll: true }}
                        className="space-y-6"
                    >
                        {({ processing, recentlySuccessful, errors }) => (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="access_token">
                                        Access Token (xoxe.xoxp-...)
                                    </Label>
                                    <TokenInput
                                        id="access_token"
                                        name="access_token"
                                        required
                                        placeholder="xoxe.xoxp-..."
                                    />
                                    <InputError
                                        className="mt-2"
                                        message={errors.access_token}
                                    />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="refresh_token">
                                        Refresh Token (xoxe-...)
                                    </Label>
                                    <TokenInput
                                        id="refresh_token"
                                        name="refresh_token"
                                        required
                                        placeholder="xoxe-..."
                                    />
                                    <InputError
                                        className="mt-2"
                                        message={errors.refresh_token}
                                    />
                                </div>

                                <div className="flex items-center gap-4">
                                    <Button disabled={processing}>
                                        {configToken
                                            ? 'Replace Token'
                                            : 'Save Token'}
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
