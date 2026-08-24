import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { ClaudeAuthCard } from '@/components/agents/claude-auth-card';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import type { Agent, BreadcrumbItem } from '@/types';

export default function ConnectClaude({ agent }: { agent: Agent }) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Agents', href: '/agents' },
        { title: agent.name, href: `/agents/${agent.id}` },
        { title: 'Connect Claude', href: `/agents/${agent.id}/connect-claude` },
    ];

    const isConnected = !!agent.claude_connected_at;
    const [switching, setSwitching] = useState(false);

    const switchToPayPerUse = () => {
        setSwitching(true);
        router.post(
            `/agents/${agent.id}/use-pay-per-use`,
            {},
            {
                preserveScroll: true,
                onFinish: () => setSwitching(false),
            },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Connect Claude — ${agent.name}`} />

            <div className="mx-auto max-w-xl px-4 py-10">
                <div className="space-y-6">
                    <div className="text-center">
                        <div className="text-5xl">🔗</div>
                        <h1 className="mt-3 text-2xl font-bold tracking-tight">
                            Connect your Claude subscription
                        </h1>
                        <p className="mt-2 text-sm text-muted-foreground">
                            {agent.name} runs on Claude via your own Anthropic
                            subscription. Paste a setup token once — usage is
                            billed on your existing Claude plan, not through
                            Provision.
                        </p>
                    </div>

                    <ClaudeAuthCard agent={agent} />

                    <div className="flex items-center justify-between gap-4 border-t border-border pt-6">
                        <button
                            type="button"
                            onClick={switchToPayPerUse}
                            disabled={switching}
                            className="text-sm text-muted-foreground underline hover:text-foreground disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {switching
                                ? 'Switching…'
                                : 'Switch to pay-per-use instead'}
                        </button>

                        <Button
                            disabled={!isConnected}
                            onClick={() =>
                                router.visit(`/agents/${agent.id}/setup`)
                            }
                        >
                            {isConnected
                                ? 'Continue'
                                : 'Connect first to continue'}
                        </Button>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
