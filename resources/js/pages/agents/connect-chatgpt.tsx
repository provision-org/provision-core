import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { ChatGPTAuthCard } from '@/components/agents/chatgpt-auth-card';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import type { Agent, BreadcrumbItem } from '@/types';

export default function ConnectChatGPT({ agent }: { agent: Agent }) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Agents', href: '/agents' },
        { title: agent.name, href: `/agents/${agent.id}` },
        { title: 'Connect ChatGPT', href: `/agents/${agent.id}/connect-chatgpt` },
    ];

    const isConnected = !!agent.chatgpt_email;
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
            <Head title={`Connect ChatGPT — ${agent.name}`} />

            <div className="mx-auto max-w-xl px-4 py-10">
                <div className="space-y-6">
                    <div className="text-center">
                        <div className="text-5xl">🔗</div>
                        <h1 className="mt-3 text-2xl font-bold tracking-tight">
                            Connect your ChatGPT account
                        </h1>
                        <p className="mt-2 text-sm text-muted-foreground">
                            {agent.name} runs on GPT-5 via your ChatGPT
                            subscription. Pair your account once — usage is
                            billed on your existing OpenAI plan, not through
                            Provision.
                        </p>
                    </div>

                    <ChatGPTAuthCard agent={agent} />

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
                            {isConnected ? 'Continue' : 'Connect first to continue'}
                        </Button>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
