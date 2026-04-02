import { Form, Head, router } from '@inertiajs/react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import type { Agent, BreadcrumbItem } from '@/types';

export default function DiscordSetup({ agent }: { agent: Agent }) {
    const connection = agent.discord_connection;
    const isConnected = connection?.status === 'connected';

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Agents', href: '/agents' },
        { title: agent.name, href: `/agents/${agent.id}` },
        { title: 'Discord Setup', href: `/agents/${agent.id}/discord` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Discord Setup" />

            <div className="mx-auto max-w-2xl space-y-12 px-4 py-6">
                {isConnected ? (
                    <div className="space-y-6">
                        <Heading
                            variant="small"
                            title="Discord Connected"
                            description="This agent is connected to Discord and ready to receive messages."
                        />

                        <div className="flex items-center gap-3">
                            <Badge>Connected</Badge>
                            {connection?.bot_username && (
                                <span className="text-sm text-muted-foreground">
                                    {connection.bot_username}
                                </span>
                            )}
                            {connection?.guild_id && (
                                <span className="text-sm text-muted-foreground">
                                    Guild: {connection.guild_id}
                                </span>
                            )}
                        </div>

                        <Button
                            variant="destructive"
                            onClick={() =>
                                router.delete(`/agents/${agent.id}/discord`)
                            }
                        >
                            Disconnect Discord
                        </Button>
                    </div>
                ) : (
                    <div className="space-y-6">
                        <Heading
                            variant="small"
                            title="Connect Discord"
                            description="Connect this agent to Discord so it can interact in your server."
                        />

                        <div className="rounded-md bg-muted p-4 text-sm">
                            <p className="mb-1.5 font-medium">
                                How to create a Discord bot:
                            </p>
                            <ol className="list-inside list-decimal space-y-1 text-muted-foreground">
                                <li>
                                    Go to the{' '}
                                    <a
                                        href="https://discord.com/developers/applications"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="text-foreground underline"
                                    >
                                        Discord Developer Portal
                                    </a>
                                </li>
                                <li>Create a new application and add a bot</li>
                                <li>
                                    Under "Bot", click "Reset Token" and copy it
                                </li>
                                <li>
                                    Enable "Message Content Intent" under
                                    Privileged Gateway Intents
                                </li>
                                <li>
                                    Invite the bot to your server using the
                                    OAuth2 URL Generator
                                </li>
                            </ol>
                        </div>

                        <Form
                            action={`/agents/${agent.id}/discord`}
                            method="post"
                            options={{ preserveScroll: true }}
                            className="space-y-6"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="token">Bot Token</Label>
                                        <Input
                                            id="token"
                                            name="token"
                                            type="password"
                                            required
                                            placeholder="MTIz..."
                                        />
                                        <InputError
                                            className="mt-2"
                                            message={errors.token}
                                        />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="guild_id">
                                            Server ID{' '}
                                            <span className="text-muted-foreground">
                                                (optional)
                                            </span>
                                        </Label>
                                        <Input
                                            id="guild_id"
                                            name="guild_id"
                                            placeholder="123456789012345678"
                                        />
                                        <p className="text-xs text-muted-foreground">
                                            Restrict the bot to a specific
                                            server. Right-click your server and
                                            select "Copy Server ID".
                                        </p>
                                        <InputError
                                            className="mt-2"
                                            message={errors.guild_id}
                                        />
                                    </div>

                                    <div className="flex items-center gap-3">
                                        <input
                                            id="require_mention"
                                            name="require_mention"
                                            type="checkbox"
                                            defaultChecked={true}
                                            value="1"
                                            className="size-4 rounded border-input"
                                        />
                                        <Label
                                            htmlFor="require_mention"
                                            className="text-sm font-normal"
                                        >
                                            Require @mention to respond in
                                            channels
                                        </Label>
                                    </div>

                                    <Button disabled={processing}>
                                        {processing
                                            ? 'Connecting...'
                                            : 'Connect Discord'}
                                    </Button>
                                </>
                            )}
                        </Form>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
