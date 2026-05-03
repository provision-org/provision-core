import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import type { Agent } from '@/types';

type StartResponse = {
    verification_url: string;
    user_code: string;
    expires_at: number;
    session: string;
};

type StatusResponse = {
    state: 'pending' | 'active' | 'expired' | 'disconnected';
    email?: string;
    plan_type?: string;
    expires_at?: string;
};

export function ChatGPTAuthCard({ agent }: { agent: Agent }) {
    const [open, setOpen] = useState(false);
    const [start, setStart] = useState<StartResponse | null>(null);
    const [status, setStatus] = useState<StatusResponse | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const pollRef = useRef<number | null>(null);

    const isConnected = !!agent.chatgpt_email;

    useEffect(
        () => () => {
            if (pollRef.current) window.clearInterval(pollRef.current);
        },
        [],
    );

    async function handleConnect() {
        setError(null);
        setBusy(true);

        try {
            const res = await fetch(`/agents/${agent.id}/chatgpt-auth`, {
                method: 'POST',
                headers: csrfHeaders(),
                credentials: 'same-origin',
            });

            if (!res.ok) {
                const body = await res.json().catch(() => ({}));
                throw new Error(body.message ?? `HTTP ${res.status}`);
            }

            const data: StartResponse = await res.json();
            setStart(data);
            setStatus({ state: 'pending' });
            setOpen(true);

            pollRef.current = window.setInterval(pollStatus, 3000);
        } catch (e) {
            setError(e instanceof Error ? e.message : String(e));
        } finally {
            setBusy(false);
        }
    }

    async function pollStatus() {
        try {
            const res = await fetch(`/agents/${agent.id}/chatgpt-auth`, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            });
            const data: StatusResponse = await res.json();
            setStatus(data);

            if (data.state === 'active') {
                if (pollRef.current) window.clearInterval(pollRef.current);

                window.setTimeout(() => {
                    setOpen(false);
                    router.reload({ only: ['agent'] });
                }, 1500);
            }

            if (data.state === 'expired') {
                if (pollRef.current) window.clearInterval(pollRef.current);
            }
        } catch {
            // Treat transient poll failures as still-pending; keep polling.
        }
    }

    async function handleDisconnect() {
        if (!confirm('Disconnect ChatGPT subscription from this agent?'))
            return;

        setBusy(true);

        try {
            await fetch(`/agents/${agent.id}/chatgpt-auth`, {
                method: 'DELETE',
                headers: csrfHeaders(),
                credentials: 'same-origin',
            });
            router.reload({ only: ['agent'] });
        } finally {
            setBusy(false);
        }
    }

    return (
        <div className="rounded-lg border border-border bg-card p-4">
            <div className="flex items-start justify-between gap-4">
                <div>
                    <h3 className="text-sm font-semibold">
                        ChatGPT subscription
                    </h3>
                    <p className="mt-1 text-xs text-muted-foreground">
                        {isConnected
                            ? `Connected as ${agent.chatgpt_email}${agent.chatgpt_plan_type ? ` (${agent.chatgpt_plan_type})` : ''}. Codex models are billed against this account.`
                            : 'Use your ChatGPT Pro/Team subscription instead of pay-as-you-go API billing for GPT-5.4/5.5 models.'}
                    </p>
                </div>

                <div className="shrink-0">
                    {isConnected ? (
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={busy}
                            onClick={handleDisconnect}
                        >
                            Disconnect
                        </Button>
                    ) : (
                        <Button
                            size="sm"
                            disabled={busy || !agent.server_id}
                            onClick={handleConnect}
                        >
                            {busy ? 'Starting…' : 'Connect ChatGPT'}
                        </Button>
                    )}
                </div>
            </div>

            {error && (
                <p className="mt-3 rounded bg-destructive/10 px-3 py-2 text-xs text-destructive">
                    {error}
                </p>
            )}

            {open && start && (
                <div className="bg-background/80 fixed inset-0 z-50 flex items-center justify-center backdrop-blur-sm">
                    <div className="w-full max-w-md rounded-lg border border-border bg-card p-6 shadow-2xl">
                        <h2 className="text-lg font-semibold">
                            Connect ChatGPT
                        </h2>

                        {status?.state === 'active' ? (
                            <div className="mt-4 rounded bg-emerald-100 p-4 text-sm dark:bg-emerald-900/30">
                                ✅ Connected as{' '}
                                <strong>{status.email}</strong>
                                {status.plan_type ? ` (${status.plan_type})` : ''}
                            </div>
                        ) : status?.state === 'expired' ? (
                            <div className="mt-4">
                                <p className="text-sm text-destructive">
                                    Code expired. Try again.
                                </p>
                                <Button
                                    className="mt-3"
                                    size="sm"
                                    onClick={() => {
                                        setOpen(false);
                                        handleConnect();
                                    }}
                                >
                                    Restart
                                </Button>
                            </div>
                        ) : (
                            <>
                                <ol className="mt-4 list-decimal space-y-3 pl-5 text-sm">
                                    <li>
                                        Open{' '}
                                        <a
                                            href={start.verification_url}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="text-primary underline"
                                        >
                                            {start.verification_url}
                                        </a>{' '}
                                        and sign in with ChatGPT.
                                    </li>
                                    <li>
                                        Enter this code:
                                        <div className="mt-2 rounded bg-muted px-4 py-3 text-center font-mono text-2xl tracking-widest">
                                            {start.user_code}
                                        </div>
                                    </li>
                                    <li>
                                        Wait — we'll detect the connection
                                        automatically.
                                    </li>
                                </ol>

                                <p className="mt-4 text-xs text-muted-foreground">
                                    Code expires in 15 minutes. Polling every
                                    3s…
                                </p>
                            </>
                        )}

                        <div className="mt-6 flex justify-end">
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => {
                                    if (pollRef.current)
                                        window.clearInterval(pollRef.current);
                                    setOpen(false);
                                }}
                            >
                                Close
                            </Button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}

function csrfHeaders(): HeadersInit {
    const token = decodeURIComponent(
        document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '',
    );

    return {
        'Content-Type': 'application/json',
        'X-XSRF-TOKEN': token,
        'X-Requested-With': 'XMLHttpRequest',
        Accept: 'application/json',
    };
}
