import { Transition } from '@headlessui/react';
import { Form, Head, router, useForm } from '@inertiajs/react';
import { Eye, EyeOff } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
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
import { useClipboard } from '@/hooks/use-clipboard';
import AppLayout from '@/layouts/app-layout';
import type {
    Agent,
    BreadcrumbItem,
    SlackDmPolicy,
    SlackDmSessionScope,
    SlackGroupPolicy,
    SlackReplyToMode,
} from '@/types';

type SlackSetupStep =
    | 'no-config'
    | 'create-app'
    | 'oauth-pending'
    | 'enter-xapp'
    | 'configure-preferences'
    | 'connected';

function TokenInput({
    id,
    name,
    placeholder,
    required,
    value,
    onChange,
}: {
    id: string;
    name: string;
    placeholder: string;
    required?: boolean;
    value?: string;
    onChange?: (e: React.ChangeEvent<HTMLInputElement>) => void;
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
                value={value}
                onChange={onChange}
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

export default function SlackSetup({
    agent,
    step,
    manifestYaml,
}: {
    agent: Agent;
    step: SlackSetupStep;
    hasConfigToken: boolean;
    manifestYaml: string;
}) {
    const [copiedText, copy] = useClipboard();
    const [showManual, setShowManual] = useState(false);

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
            title: 'Slack Setup',
            href: `/agents/${agent.id}/slack`,
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Slack Setup" />

            <div className="mx-auto max-w-2xl space-y-12 px-4 py-6">
                {/* Automated Flow */}
                {step === 'no-config' && (
                    <NoConfigStep agentTeamId={agent.team_id} />
                )}

                {step === 'create-app' && <CreateAppStep agent={agent} />}

                {step === 'oauth-pending' && <OAuthPendingStep agent={agent} />}

                {step === 'enter-xapp' && <EnterXappStep agent={agent} />}

                {step === 'configure-preferences' && (
                    <PreferencesStep agent={agent} />
                )}

                {step === 'connected' && <ConnectedStep agent={agent} />}

                {/* Manual Setup Toggle */}
                {step !== 'connected' && step !== 'configure-preferences' && (
                    <>
                        <Separator />

                        <div className="space-y-6">
                            <button
                                type="button"
                                onClick={() => setShowManual(!showManual)}
                                className="text-sm text-muted-foreground underline hover:text-foreground"
                            >
                                {showManual
                                    ? 'Hide manual setup'
                                    : 'Set up manually instead'}
                            </button>

                            {showManual && (
                                <ManualSetup
                                    agent={agent}
                                    manifestYaml={manifestYaml}
                                    copiedText={copiedText}
                                    copy={copy}
                                />
                            )}
                        </div>
                    </>
                )}
            </div>
        </AppLayout>
    );
}

function NoConfigStep({ agentTeamId }: { agentTeamId: string }) {
    const [dialogOpen, setDialogOpen] = useState(false);

    const form = useForm({
        access_token: '',
        refresh_token: '',
    });

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        form.post(`/settings/teams/${agentTeamId}/slack-config`, {
            preserveScroll: true,
            onSuccess: () => setDialogOpen(false),
        });
    }

    return (
        <div className="space-y-6">
            <Heading
                variant="small"
                title="Connect Your Slack Workspace"
                description="Before creating Slack apps for agents, you need to set up a Slack Configuration Token for your team."
            />

            <div className="space-y-3 rounded-md border border-border p-4">
                <p className="text-sm text-muted-foreground">
                    A Configuration Token allows Provision to automatically
                    create and manage Slack apps on your behalf.
                </p>
                <Button onClick={() => setDialogOpen(true)}>
                    Configure Slack Token
                </Button>
            </div>

            <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Slack Configuration Token</DialogTitle>
                        <DialogDescription>
                            Generate a Configuration Token from your Slack
                            workspace to enable automated app creation.
                        </DialogDescription>
                    </DialogHeader>

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

                    <form onSubmit={handleSubmit} className="space-y-4">
                        <div className="grid gap-2">
                            <Label htmlFor="config_access_token">
                                Access Token (xoxe.xoxp-...)
                            </Label>
                            <TokenInput
                                id="config_access_token"
                                name="access_token"
                                placeholder="xoxe.xoxp-..."
                                required
                                value={form.data.access_token}
                                onChange={(e) =>
                                    form.setData('access_token', e.target.value)
                                }
                            />
                            <InputError message={form.errors.access_token} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="config_refresh_token">
                                Refresh Token (xoxe-...)
                            </Label>
                            <TokenInput
                                id="config_refresh_token"
                                name="refresh_token"
                                placeholder="xoxe-..."
                                required
                                value={form.data.refresh_token}
                                onChange={(e) =>
                                    form.setData(
                                        'refresh_token',
                                        e.target.value,
                                    )
                                }
                            />
                            <InputError message={form.errors.refresh_token} />
                        </div>

                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setDialogOpen(false)}
                            >
                                Cancel
                            </Button>
                            <Button type="submit" disabled={form.processing}>
                                {form.processing ? 'Saving...' : 'Save Token'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </div>
    );
}

function CreateAppStep({ agent }: { agent: Agent }) {
    return (
        <div className="space-y-6">
            <Heading
                variant="small"
                title={`Create Slack App for ${agent.name}`}
                description="We'll automatically create a Slack app, then ask you to authorize it for your workspace."
            />

            <Form
                action={`/agents/${agent.id}/slack/create-app`}
                method="post"
                className="space-y-4"
            >
                {({ processing, errors }) => (
                    <>
                        <InputError message={errors.slack} />
                        <InputError message={errors.config_token} />
                        <Button disabled={processing}>
                            {processing
                                ? 'Creating App...'
                                : 'Create Slack App'}
                        </Button>
                    </>
                )}
            </Form>
        </div>
    );
}

function OAuthPendingStep({ agent }: { agent: Agent }) {
    return (
        <div className="space-y-6">
            <Heading
                variant="small"
                title="Authorize the Slack App"
                description="The app was created but hasn't been authorized yet. Click below to authorize it in Slack."
            />

            <Form
                action={`/agents/${agent.id}/slack/create-app`}
                method="post"
                className="space-y-4"
            >
                {({ processing }) => (
                    <Button disabled={processing}>
                        {processing ? 'Redirecting...' : 'Authorize in Slack'}
                    </Button>
                )}
            </Form>
        </div>
    );
}

function EnterXappStep({ agent }: { agent: Agent }) {
    const slackAppId = agent.slack_connection?.slack_app_id;

    return (
        <div className="space-y-6">
            <Heading
                variant="small"
                title="Almost Done! Enter App-Level Token"
                description="The Slack app has been created and authorized. One last step: generate an App-Level Token."
            />

            <div className="space-y-2 rounded-md bg-muted p-4 text-sm">
                <p className="font-medium">How to generate the token:</p>
                <ol className="list-inside list-decimal space-y-1 text-muted-foreground">
                    <li>
                        <a
                            href={`https://api.slack.com/apps/${slackAppId}/general`}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="text-foreground underline"
                        >
                            Open your Slack app settings
                        </a>
                    </li>
                    <li>
                        Scroll to "App-Level Tokens" and click "Generate Token
                        and Scopes"
                    </li>
                    <li>
                        Name it anything (e.g. "socket"), add the{' '}
                        <code className="rounded bg-background px-1 py-0.5">
                            connections:write
                        </code>{' '}
                        scope
                    </li>
                    <li>Copy the token (starts with xapp-) and paste below</li>
                </ol>
            </div>

            <Form
                action={`/agents/${agent.id}/slack/app-token`}
                method="post"
                options={{ preserveScroll: true }}
                className="space-y-6"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-2">
                            <Label htmlFor="app_token">
                                App-Level Token (xapp-...)
                            </Label>
                            <TokenInput
                                id="app_token"
                                name="app_token"
                                required
                                placeholder="xapp-..."
                            />
                            <InputError
                                className="mt-2"
                                message={errors.app_token}
                            />
                        </div>

                        <Button disabled={processing}>Complete Setup</Button>
                    </>
                )}
            </Form>
        </div>
    );
}

function PreferencesStep({ agent }: { agent: Agent }) {
    const form = useForm({
        dm_policy: 'open' as SlackDmPolicy,
        group_policy: 'open' as SlackGroupPolicy,
        require_mention: true,
        reply_to_mode: 'all' as SlackReplyToMode,
        dm_session_scope: 'per-peer' as SlackDmSessionScope,
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        form.post(`/agents/${agent.id}/slack/preferences`);
    }

    return (
        <div className="space-y-8">
            <Heading
                variant="small"
                title="How should your agent interact?"
                description="Choose how your agent responds in Slack. You can change these settings later."
            />

            <form onSubmit={submit} className="space-y-6">
                {/* DM Policy */}
                <div className="grid gap-2">
                    <Label htmlFor="pref_dm_policy">Direct Messages</Label>
                    <Select
                        value={form.data.dm_policy}
                        onValueChange={(v) =>
                            form.setData('dm_policy', v as SlackDmPolicy)
                        }
                    >
                        <SelectTrigger id="pref_dm_policy">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="open">
                                Accept DMs from anyone
                            </SelectItem>
                            <SelectItem value="disabled">
                                Disable direct messages
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <p className="text-xs text-muted-foreground">
                        Whether people can message your agent directly.
                    </p>
                </div>

                {/* DM Session Scope */}
                {form.data.dm_policy === 'open' && (
                    <div className="grid gap-2">
                        <Label htmlFor="pref_dm_session_scope">DM Memory</Label>
                        <Select
                            value={form.data.dm_session_scope}
                            onValueChange={(v) =>
                                form.setData(
                                    'dm_session_scope',
                                    v as SlackDmSessionScope,
                                )
                            }
                        >
                            <SelectTrigger id="pref_dm_session_scope">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="per-peer">
                                    Separate conversation per person
                                </SelectItem>
                                <SelectItem value="main">
                                    Shared conversation for everyone
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <p className="text-xs text-muted-foreground">
                            Per-person keeps each conversation isolated. Shared
                            means everyone sees the same context.
                        </p>
                    </div>
                )}

                {/* Group/Channel Policy */}
                <div className="grid gap-2">
                    <Label htmlFor="pref_group_policy">
                        Channels &amp; Groups
                    </Label>
                    <Select
                        value={form.data.group_policy}
                        onValueChange={(v) =>
                            form.setData('group_policy', v as SlackGroupPolicy)
                        }
                    >
                        <SelectTrigger id="pref_group_policy">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="open">
                                Respond in channels
                            </SelectItem>
                            <SelectItem value="disabled">
                                Disable channel responses
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <p className="text-xs text-muted-foreground">
                        Whether your agent responds in channels it&apos;s added
                        to.
                    </p>
                </div>

                {/* Require @mention */}
                {form.data.group_policy === 'open' && (
                    <div className="grid gap-2">
                        <Label htmlFor="pref_require_mention">
                            Channel Trigger
                        </Label>
                        <Select
                            value={form.data.require_mention ? 'yes' : 'no'}
                            onValueChange={(v) =>
                                form.setData('require_mention', v === 'yes')
                            }
                        >
                            <SelectTrigger id="pref_require_mention">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="yes">
                                    Only when @mentioned
                                </SelectItem>
                                <SelectItem value="no">
                                    Respond to every message
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <p className="text-xs text-muted-foreground">
                            @mention is recommended to avoid noisy channels.
                        </p>
                    </div>
                )}

                {/* Reply Threading */}
                <div className="grid gap-2">
                    <Label htmlFor="pref_reply_to_mode">Reply Threading</Label>
                    <Select
                        value={form.data.reply_to_mode}
                        onValueChange={(v) =>
                            form.setData('reply_to_mode', v as SlackReplyToMode)
                        }
                    >
                        <SelectTrigger id="pref_reply_to_mode">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">
                                Always reply in thread
                            </SelectItem>
                            <SelectItem value="first">
                                Thread first reply only
                            </SelectItem>
                            <SelectItem value="off">
                                Post in channel (no threading)
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <p className="text-xs text-muted-foreground">
                        Threading keeps channels tidy. Recommended for most
                        setups.
                    </p>
                </div>

                <Button type="submit" disabled={form.processing}>
                    {form.processing ? 'Saving...' : 'Continue'}
                </Button>
            </form>
        </div>
    );
}

function ConnectedStep({ agent }: { agent: Agent }) {
    const slack = agent.slack_connection;

    const form = useForm({
        dm_policy: (slack?.dm_policy ?? 'open') as SlackDmPolicy,
        group_policy: (slack?.group_policy ?? 'open') as SlackGroupPolicy,
        require_mention: slack?.require_mention ?? false,
        reply_to_mode: (slack?.reply_to_mode ?? 'off') as SlackReplyToMode,
        dm_session_scope: (slack?.dm_session_scope ??
            'main') as SlackDmSessionScope,
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        form.patch(`/agents/${agent.id}/slack/settings`);
    }

    return (
        <div className="space-y-8">
            <div className="space-y-4">
                <Heading
                    variant="small"
                    title="Slack Connected"
                    description="This agent is connected to Slack and ready to go."
                />

                <div className="flex items-center gap-3">
                    <Badge>Connected</Badge>
                    {slack?.is_automated && (
                        <span className="text-sm text-muted-foreground">
                            Automated setup
                        </span>
                    )}
                </div>
            </div>

            <Separator />

            <form onSubmit={submit} className="space-y-8">
                <Heading
                    variant="small"
                    title="Channel Settings"
                    description="Control how your agent interacts with Slack messages."
                />

                <div className="grid gap-6">
                    {/* DM Policy */}
                    <div className="grid gap-2">
                        <Label htmlFor="dm_policy">Direct Messages</Label>
                        <Select
                            value={form.data.dm_policy}
                            onValueChange={(v) =>
                                form.setData('dm_policy', v as SlackDmPolicy)
                            }
                        >
                            <SelectTrigger id="dm_policy">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="open">
                                    Accept from anyone
                                </SelectItem>
                                <SelectItem value="disabled">
                                    Disabled
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <p className="text-xs text-muted-foreground">
                            Whether the agent responds to direct messages.
                        </p>
                        <InputError message={form.errors.dm_policy} />
                    </div>

                    {/* Group/Channel Policy */}
                    <div className="grid gap-2">
                        <Label htmlFor="group_policy">
                            Channels &amp; Groups
                        </Label>
                        <Select
                            value={form.data.group_policy}
                            onValueChange={(v) =>
                                form.setData(
                                    'group_policy',
                                    v as SlackGroupPolicy,
                                )
                            }
                        >
                            <SelectTrigger id="group_policy">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="open">
                                    Respond in all channels
                                </SelectItem>
                                <SelectItem value="disabled">
                                    Disabled
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <p className="text-xs text-muted-foreground">
                            Whether the agent responds in channels and group
                            conversations.
                        </p>
                        <InputError message={form.errors.group_policy} />
                    </div>

                    {/* Require @mention */}
                    {form.data.group_policy === 'open' && (
                        <div className="grid gap-2">
                            <Label htmlFor="require_mention">
                                Require @mention in channels
                            </Label>
                            <Select
                                value={form.data.require_mention ? 'yes' : 'no'}
                                onValueChange={(v) =>
                                    form.setData('require_mention', v === 'yes')
                                }
                            >
                                <SelectTrigger id="require_mention">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="no">
                                        Respond to all messages
                                    </SelectItem>
                                    <SelectItem value="yes">
                                        Only when @mentioned
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <p className="text-xs text-muted-foreground">
                                If enabled, the agent only responds when
                                directly @mentioned in channels.
                            </p>
                            <InputError message={form.errors.require_mention} />
                        </div>
                    )}

                    {/* Reply Threading */}
                    <div className="grid gap-2">
                        <Label htmlFor="reply_to_mode">Reply Threading</Label>
                        <Select
                            value={form.data.reply_to_mode}
                            onValueChange={(v) =>
                                form.setData(
                                    'reply_to_mode',
                                    v as SlackReplyToMode,
                                )
                            }
                        >
                            <SelectTrigger id="reply_to_mode">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="off">
                                    Post in channel (no threading)
                                </SelectItem>
                                <SelectItem value="first">
                                    Thread first reply only
                                </SelectItem>
                                <SelectItem value="all">
                                    Always reply in thread
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <p className="text-xs text-muted-foreground">
                            How the agent uses threads when replying to
                            messages.
                        </p>
                        <InputError message={form.errors.reply_to_mode} />
                    </div>

                    {/* DM Session Scope */}
                    {form.data.dm_policy === 'open' && (
                        <div className="grid gap-2">
                            <Label htmlFor="dm_session_scope">DM Memory</Label>
                            <Select
                                value={form.data.dm_session_scope}
                                onValueChange={(v) =>
                                    form.setData(
                                        'dm_session_scope',
                                        v as SlackDmSessionScope,
                                    )
                                }
                            >
                                <SelectTrigger id="dm_session_scope">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="main">
                                        Shared context (everyone sees same
                                        conversation)
                                    </SelectItem>
                                    <SelectItem value="per-peer">
                                        Isolated per person
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <p className="text-xs text-muted-foreground">
                                Whether DM conversations share context or are
                                isolated per person.
                            </p>
                            <InputError
                                message={form.errors.dm_session_scope}
                            />
                        </div>
                    )}
                </div>

                <div className="flex items-center gap-4">
                    <Button type="submit" disabled={form.processing}>
                        {form.processing ? 'Saving...' : 'Save Settings'}
                    </Button>

                    <Transition
                        show={form.recentlySuccessful}
                        enter="transition ease-in-out"
                        enterFrom="opacity-0"
                        leave="transition ease-in-out"
                        leaveTo="opacity-0"
                    >
                        <p className="text-sm text-muted-foreground">Saved</p>
                    </Transition>
                </div>
            </form>

            <Separator />

            <Button
                variant="destructive"
                onClick={() => router.delete(`/agents/${agent.id}/slack`)}
            >
                Disconnect Slack
            </Button>
        </div>
    );
}

function ManualSetup({
    agent,
    manifestYaml,
    copiedText,
    copy,
}: {
    agent: Agent;
    manifestYaml: string;
    copiedText: string | null;
    copy: (text: string) => void;
}) {
    return (
        <div className="space-y-12">
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
                    action={`/agents/${agent.id}/slack`}
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
                                <TokenInput
                                    id="bot_token"
                                    name="bot_token"
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
                                <TokenInput
                                    id="app_token"
                                    name="app_token"
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
        </div>
    );
}
