import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { Agent } from '@/types';

type ConnectResponse = {
    state: 'active';
    expires_at: string | null;
};

export function ClaudeAuthCard({ agent }: { agent: Agent }) {
    const [setupToken, setSetupToken] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [justConnected, setJustConnected] = useState(false);

    const isConnected =
        agent.auth_provider === 'claude' && !!agent.claude_connected_at;

    async function handleConnect() {
        setError(null);
        setBusy(true);

        try {
            const res = await fetch(`/agents/${agent.id}/claude-auth`, {
                method: 'POST',
                headers: csrfHeaders(),
                credentials: 'same-origin',
                body: JSON.stringify({ setup_token: setupToken.trim() }),
            });

            if (!res.ok) {
                const body = await res.json().catch(() => ({}));
                throw new Error(body.message ?? `HTTP ${res.status}`);
            }

            const data: ConnectResponse = await res.json();

            if (data.state === 'active') {
                // Hold the success state on screen for a beat so the user sees
                // confirmation, then navigate. router.visit beats
                // router.reload — partial reloads can short-circuit the
                // controller-level redirect out of the connect page once
                // claude_connected_at is set.
                setJustConnected(true);
                window.setTimeout(() => {
                    router.visit(`/agents/${agent.id}/setup`);
                }, 1500);
            }
        } catch (e) {
            setError(e instanceof Error ? e.message : String(e));
            setBusy(false);
        }
    }

    async function handleDisconnect() {
        if (!confirm('Disconnect Claude subscription from this agent?')) return;

        setBusy(true);

        try {
            await fetch(`/agents/${agent.id}/claude-auth`, {
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
                        Claude subscription
                    </h3>
                    <p className="mt-1 text-xs text-muted-foreground">
                        {isConnected
                            ? `Connected ${formatConnectedAt(agent.claude_connected_at)}. Anthropic models are billed against your Claude plan.`
                            : 'Use your Claude Pro/Max subscription instead of pay-as-you-go API billing for Anthropic models.'}
                    </p>
                </div>

                {isConnected && (
                    <div className="shrink-0">
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={busy}
                            onClick={handleDisconnect}
                        >
                            Disconnect
                        </Button>
                    </div>
                )}
            </div>

            {justConnected ? (
                <div className="mt-4 rounded bg-emerald-100 p-4 text-sm dark:bg-emerald-900/30">
                    ✅ Connected — your Claude subscription is now powering this
                    agent.
                </div>
            ) : (
                !isConnected && (
                    <>
                        <ol className="mt-4 list-decimal space-y-2 pl-5 text-sm">
                            <li>
                                Install{' '}
                                <a
                                    href="https://docs.anthropic.com/en/docs/claude-code/overview"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="text-primary underline"
                                >
                                    Claude Code
                                </a>{' '}
                                on your own machine and sign in with your Claude
                                subscription.
                            </li>
                            <li>
                                Run{' '}
                                <code className="rounded bg-muted px-1.5 py-0.5 font-mono text-xs">
                                    claude setup-token
                                </code>{' '}
                                in your terminal.
                            </li>
                            <li>
                                Paste the generated token (starts with{' '}
                                <code className="rounded bg-muted px-1.5 py-0.5 font-mono text-xs">
                                    sk-ant-oat01-
                                </code>
                                ) below.
                            </li>
                        </ol>

                        <div className="mt-4 flex items-center gap-2">
                            <Input
                                type="password"
                                autoComplete="off"
                                placeholder="sk-ant-oat01-…"
                                value={setupToken}
                                disabled={busy}
                                onChange={(e) => setSetupToken(e.target.value)}
                            />
                            <Button
                                size="sm"
                                disabled={
                                    busy ||
                                    !setupToken.trim() ||
                                    !agent.server_id
                                }
                                onClick={handleConnect}
                            >
                                {busy ? 'Connecting…' : 'Connect'}
                            </Button>
                        </div>
                    </>
                )
            )}

            {error && (
                <p className="mt-3 rounded bg-destructive/10 px-3 py-2 text-xs text-destructive">
                    {error}
                </p>
            )}

            <div className="mt-4 rounded bg-muted/50 px-3 py-2.5 text-xs text-muted-foreground">
                <p className="font-medium">Before you rely on this:</p>
                <ul className="mt-1 list-disc space-y-1 pl-4">
                    <li>
                        Setup tokens can expire or be revoked by Anthropic — you
                        may need to reconnect from time to time.
                    </li>
                    <li>
                        Usage draws from your own Claude subscription limits.
                    </li>
                    <li>
                        For shared or pooled production workloads, use an
                        Anthropic API key instead.
                    </li>
                </ul>
            </div>
        </div>
    );
}

function formatConnectedAt(connectedAt: string | null): string {
    if (!connectedAt) return '';

    return new Date(connectedAt).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
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
