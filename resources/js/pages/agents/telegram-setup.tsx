import { Form, Head, router } from '@inertiajs/react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import type { Agent, BreadcrumbItem } from '@/types';

export default function TelegramSetup({ agent }: { agent: Agent }) {
    const connection = agent.telegram_connection;
    const isConnected = connection?.status === 'connected';

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Agents', href: '/agents' },
        { title: agent.name, href: `/agents/${agent.id}` },
        { title: 'Telegram Setup', href: `/agents/${agent.id}/telegram` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Telegram Setup" />

            <div className="mx-auto max-w-2xl space-y-12 px-4 py-6">
                {isConnected ? (
                    <div className="space-y-6">
                        <Heading
                            variant="small"
                            title="Telegram Connected"
                            description="This agent is connected to Telegram and ready to receive messages."
                        />

                        <div className="flex items-center gap-3">
                            <Badge>Connected</Badge>
                            {connection?.bot_username && (
                                <span className="text-sm text-muted-foreground">
                                    @{connection.bot_username}
                                </span>
                            )}
                        </div>

                        <Button
                            variant="destructive"
                            onClick={() =>
                                router.delete(`/agents/${agent.id}/telegram`)
                            }
                        >
                            Disconnect Telegram
                        </Button>
                    </div>
                ) : (
                    <div className="space-y-6">
                        <Heading
                            variant="small"
                            title="Connect Telegram"
                            description="Connect this agent to Telegram so it can receive and respond to messages."
                        />

                        <div className="rounded-md bg-muted p-4 text-sm">
                            <p className="mb-1.5 font-medium">
                                How to create a Telegram bot:
                            </p>
                            <ol className="list-inside list-decimal space-y-1 text-muted-foreground">
                                <li>
                                    Open{' '}
                                    <a
                                        href="https://t.me/BotFather"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="text-foreground underline"
                                    >
                                        @BotFather
                                    </a>{' '}
                                    in Telegram
                                </li>
                                <li>
                                    Send{' '}
                                    <code className="rounded bg-background px-1 py-0.5">
                                        /newbot
                                    </code>{' '}
                                    and follow the prompts
                                </li>
                                <li>Copy the bot token and paste it below</li>
                            </ol>
                        </div>

                        <Form
                            action={`/agents/${agent.id}/telegram`}
                            method="post"
                            options={{ preserveScroll: true }}
                            className="space-y-6"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="bot_token">
                                            Bot Token
                                        </Label>
                                        <Input
                                            id="bot_token"
                                            name="bot_token"
                                            type="password"
                                            required
                                            placeholder="123456789:ABCdefGHI..."
                                        />
                                        <InputError
                                            className="mt-2"
                                            message={errors.bot_token}
                                        />
                                    </div>

                                    <Button disabled={processing}>
                                        {processing
                                            ? 'Connecting...'
                                            : 'Connect Telegram'}
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
