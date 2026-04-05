import { Head, Link } from '@inertiajs/react';
import { Hash, MessageCircle, MessageSquare, Send } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import type { Agent, BreadcrumbItem } from '@/types';

export default function Channels({ agent }: { agent: Agent }) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Agents', href: '/agents' },
        { title: agent.name, href: `/agents/${agent.id}` },
        { title: 'Channels', href: `/agents/${agent.id}/channels` },
    ];

    const channels = [
        {
            name: 'Slack',
            description: 'Your agent joins Slack channels and DMs',
            href: `/agents/${agent.id}/slack`,
            icon: Hash,
            connected: agent.slack_connection?.status === 'connected',
        },
        {
            name: 'Telegram',
            description: 'Your agent becomes a Telegram bot',
            href: `/agents/${agent.id}/telegram`,
            icon: Send,
            connected: agent.telegram_connection?.status === 'connected',
        },
        {
            name: 'Discord',
            description: 'Your agent joins your Discord server',
            href: `/agents/${agent.id}/discord`,
            icon: MessageSquare,
            connected: agent.discord_connection?.status === 'connected',
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Connect a Channel" />

            <div className="mx-auto max-w-2xl px-4 py-6">
                <div className="space-y-8">
                    <div>
                        <h1 className="text-xl font-semibold">
                            Connect {agent.name} to a channel
                        </h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Choose how people will talk to your agent. You can
                            add more channels later.
                        </p>
                    </div>

                    {/* Web Chat — always available, no setup */}
                    <div className="rounded-lg border border-primary/20 bg-primary/[0.03] p-4">
                        <div className="flex items-center justify-between">
                            <div className="flex items-center gap-4">
                                <div className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary/10">
                                    <MessageCircle className="size-5 text-primary" />
                                </div>
                                <div>
                                    <div className="flex items-center gap-2">
                                        <span className="text-sm font-medium">
                                            Web Chat
                                        </span>
                                        <Badge
                                            variant="default"
                                            className="bg-emerald-500/10 text-[10px] text-emerald-600 hover:bg-emerald-500/10 dark:text-emerald-400"
                                        >
                                            Always on
                                        </Badge>
                                    </div>
                                    <p className="text-sm text-muted-foreground">
                                        Chat with your agent directly from the
                                        browser. No setup needed.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div className="grid gap-3">
                        {channels.map((channel) => (
                            <Link
                                key={channel.name}
                                href={channel.href}
                                className="flex items-center gap-4 rounded-lg border p-4 transition-colors hover:bg-accent/50"
                            >
                                <div className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-muted">
                                    <channel.icon className="size-5 text-muted-foreground" />
                                </div>
                                <div className="flex-1">
                                    <div className="flex items-center gap-2">
                                        <span className="text-sm font-medium">
                                            {channel.name}
                                        </span>
                                        {channel.connected && (
                                            <Badge
                                                variant="default"
                                                className="text-[10px]"
                                            >
                                                Connected
                                            </Badge>
                                        )}
                                    </div>
                                    <p className="text-sm text-muted-foreground">
                                        {channel.description}
                                    </p>
                                </div>
                            </Link>
                        ))}
                    </div>

                    <div className="flex items-center justify-between border-t pt-6">
                        <Link
                            href={`/agents/${agent.id}/provisioning`}
                            className="text-sm text-muted-foreground hover:text-foreground"
                        >
                            Skip for now
                        </Link>
                        <Button asChild>
                            <Link href={`/agents/${agent.id}/provisioning`}>
                                Continue to deploy
                            </Link>
                        </Button>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
