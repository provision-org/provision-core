import { Head, Link } from '@inertiajs/react';
import {
    AlertCircle,
    Check,
    Copy,
    Loader2,
    QrCode,
    RefreshCw,
    ShieldCheck,
    Smartphone,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import {
    showHandoff,
    storeHandoff,
} from '@/actions/App/Http/Controllers/ProvisionAppController';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { useClipboard } from '@/hooks/use-clipboard';
import AppLayout from '@/layouts/app-layout';
import type { Agent, BreadcrumbItem } from '@/types';

type PairingStatus =
    | 'idle'
    | 'preparing'
    | 'ready'
    | 'processing'
    | 'redeemed'
    | 'expired'
    | 'revoked'
    | 'failed';

type HandoffResponse = {
    handoffId: string;
    qrSvg: string;
    pairingCode: string;
    expiresAt: string;
    statusUrl: string;
};

type Props = {
    agent: Pick<Agent, 'id' | 'name' | 'emoji'>;
    canPair: boolean;
    unavailableReason: string | null;
};

function csrfToken(): string {
    return decodeURIComponent(
        document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '',
    );
}

export default function ProvisionApp({
    agent,
    canPair,
    unavailableReason,
}: Props) {
    const [status, setStatus] = useState<PairingStatus>('idle');
    const [handoff, setHandoff] = useState<HandoffResponse | null>(null);
    const [secondsRemaining, setSecondsRemaining] = useState(0);
    const [error, setError] = useState('');
    const [copiedText, copy] = useClipboard();

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Agents', href: '/agents' },
        { title: agent.name, href: `/agents/${agent.id}` },
        { title: 'Channels', href: `/agents/${agent.id}/channels` },
        { title: 'Provision App', href: `/agents/${agent.id}/provision-app` },
    ];

    useEffect(() => {
        if (!handoff || !['ready', 'processing'].includes(status)) {
            return;
        }

        function updateCountdown() {
            const remaining = Math.max(
                0,
                Math.ceil(
                    (new Date(handoff!.expiresAt).getTime() - Date.now()) /
                        1000,
                ),
            );
            setSecondsRemaining(remaining);

            if (remaining === 0 && status === 'ready') {
                setStatus('expired');
            }
        }

        updateCountdown();
        const timer = window.setInterval(updateCountdown, 1000);

        return () => window.clearInterval(timer);
    }, [handoff, status]);

    useEffect(() => {
        if (!handoff || !['ready', 'processing'].includes(status)) {
            return;
        }

        const controller = new AbortController();
        let cancelled = false;

        async function pollStatus() {
            try {
                const response = await fetch(
                    showHandoff.url({
                        agent: agent.id,
                        handoff: handoff!.handoffId,
                    }),
                    {
                        headers: {
                            Accept: 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        signal: controller.signal,
                    },
                );

                if (!response.ok || cancelled) {
                    return;
                }

                const payload = (await response.json()) as {
                    status: PairingStatus;
                };
                if (!cancelled) {
                    setStatus(payload.status);
                }
            } catch {
                // A transient polling failure should not invalidate the QR.
            }
        }

        void pollStatus();
        const timer = window.setInterval(pollStatus, 2000);

        return () => {
            cancelled = true;
            controller.abort();
            window.clearInterval(timer);
        };
    }, [agent.id, handoff, status]);

    async function generateCode() {
        setStatus('preparing');
        setHandoff(null);
        setSecondsRemaining(0);
        setError('');

        try {
            const response = await fetch(storeHandoff.url(agent.id), {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({}),
            });
            const payload = (await response.json()) as
                | HandoffResponse
                | { message?: string };

            if (!response.ok || !('handoffId' in payload)) {
                throw new Error(
                    'message' in payload && payload.message
                        ? payload.message
                        : 'Provision could not prepare mobile pairing.',
                );
            }

            setHandoff(payload);
            setStatus('ready');
        } catch (reason) {
            setStatus('failed');
            setError(
                reason instanceof Error
                    ? reason.message
                    : 'Provision could not prepare mobile pairing.',
            );
        }
    }

    const minutes = Math.floor(secondsRemaining / 60);
    const seconds = secondsRemaining % 60;
    const activeHandoff =
        handoff && ['ready', 'processing'].includes(status) ? handoff : null;
    const copied =
        activeHandoff !== null && copiedText === activeHandoff.pairingCode;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Pair Provision App — ${agent.name}`} />

            <div className="mx-auto w-full max-w-4xl px-4 py-6 sm:px-6">
                <div className="mb-6 flex items-start justify-between gap-4">
                    <div>
                        <div className="mb-2 flex items-center gap-2">
                            <h1 className="text-xl font-semibold">
                                Provision App
                            </h1>
                            <Badge variant="secondary">iOS & Android</Badge>
                        </div>
                        <p className="max-w-2xl text-sm text-muted-foreground">
                            Pair your phone securely, then chat with{' '}
                            {agent.name} and every agent on this server from the
                            Provision app.
                        </p>
                    </div>
                    <div className="hidden size-11 shrink-0 items-center justify-center rounded-xl bg-primary/10 sm:flex">
                        <Smartphone className="size-5 text-primary" />
                    </div>
                </div>

                {!canPair && unavailableReason && (
                    <Alert className="mb-5">
                        <AlertCircle />
                        <AlertTitle>Pairing is not ready yet</AlertTitle>
                        <AlertDescription>{unavailableReason}</AlertDescription>
                    </Alert>
                )}

                {status === 'redeemed' ? (
                    <Card className="border-emerald-500/30 bg-emerald-500/[0.03]">
                        <CardContent className="flex flex-col items-center py-10 text-center">
                            <div className="mb-4 flex size-12 items-center justify-center rounded-full bg-emerald-500/10">
                                <Check className="size-6 text-emerald-600 dark:text-emerald-400" />
                            </div>
                            <h2 className="text-lg font-semibold">
                                Secure handoff completed
                            </h2>
                            <p className="mt-2 max-w-md text-sm text-muted-foreground">
                                Finish the connection in the Provision app. Your
                                phone receives its own revocable device
                                credential; the server token is never shown
                                here.
                            </p>
                            <div className="mt-6 flex gap-2">
                                <Button variant="outline" asChild>
                                    <Link href={`/agents/${agent.id}`}>
                                        Back to {agent.name}
                                    </Link>
                                </Button>
                                <Button onClick={generateCode}>
                                    Pair another device
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                ) : (
                    <div className="grid gap-5 lg:grid-cols-[1fr_1.15fr]">
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <QrCode className="size-4 text-primary" />
                                    Scan with Provision
                                </CardTitle>
                                <CardDescription>
                                    Open the Provision app, choose “Pair an
                                    agent,” then scan this code.
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                {activeHandoff ? (
                                    <div className="flex flex-col items-center">
                                        <div
                                            className="aspect-square w-full max-w-[320px] overflow-hidden rounded-xl border bg-white p-3 [&_svg]:size-full"
                                            dangerouslySetInnerHTML={{
                                                __html: activeHandoff.qrSvg,
                                            }}
                                        />
                                        <div className="mt-4 flex items-center gap-2 text-xs text-muted-foreground">
                                            {status === 'processing' ? (
                                                <>
                                                    <Loader2 className="size-3.5 animate-spin" />
                                                    Completing secure handoff…
                                                </>
                                            ) : (
                                                <>
                                                    <span className="size-2 animate-pulse rounded-full bg-emerald-500" />
                                                    Expires in {minutes}:
                                                    {seconds
                                                        .toString()
                                                        .padStart(2, '0')}
                                                </>
                                            )}
                                        </div>
                                    </div>
                                ) : (
                                    <div className="flex min-h-[320px] flex-col items-center justify-center rounded-xl border border-dashed bg-muted/20 p-6 text-center">
                                        {status === 'preparing' ? (
                                            <>
                                                <Loader2 className="mb-4 size-8 animate-spin text-primary" />
                                                <p className="text-sm font-medium">
                                                    Preparing secure pairing…
                                                </p>
                                                <p className="mt-1 text-xs text-muted-foreground">
                                                    Checking the private agent
                                                    gateway.
                                                </p>
                                            </>
                                        ) : handoff ? (
                                            <>
                                                <div className="mb-4 flex size-12 items-center justify-center rounded-xl bg-destructive/10">
                                                    <AlertCircle className="size-6 text-destructive" />
                                                </div>
                                                <p className="text-sm font-medium">
                                                    This pairing code is no
                                                    longer valid
                                                </p>
                                                <p className="mt-1 max-w-xs text-xs text-muted-foreground">
                                                    Generate a new one-time code
                                                    to continue pairing.
                                                </p>
                                            </>
                                        ) : (
                                            <>
                                                <div className="mb-4 flex size-12 items-center justify-center rounded-xl bg-primary/10">
                                                    <QrCode className="size-6 text-primary" />
                                                </div>
                                                <p className="text-sm font-medium">
                                                    Generate a one-time code
                                                </p>
                                                <p className="mt-1 max-w-xs text-xs text-muted-foreground">
                                                    Each code is short-lived and
                                                    can be used by one device.
                                                </p>
                                            </>
                                        )}
                                    </div>
                                )}

                                {error && (
                                    <p className="mt-4 text-sm text-destructive">
                                        {error}
                                    </p>
                                )}

                                <Button
                                    className="mt-5 w-full"
                                    disabled={
                                        !canPair || status === 'preparing'
                                    }
                                    onClick={generateCode}
                                >
                                    {status === 'preparing' ? (
                                        <Loader2 className="animate-spin" />
                                    ) : handoff ? (
                                        <RefreshCw />
                                    ) : (
                                        <QrCode />
                                    )}
                                    {status === 'preparing'
                                        ? 'Preparing pairing code'
                                        : handoff
                                          ? 'Generate a new code'
                                          : 'Generate pairing code'}
                                </Button>
                            </CardContent>
                        </Card>

                        <div className="space-y-5">
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-base">
                                        Or enter the pairing code
                                    </CardTitle>
                                    <CardDescription>
                                        If you cannot scan the QR, copy this
                                        code and paste it into the app.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent>
                                    {activeHandoff ? (
                                        <>
                                            <div className="max-h-32 overflow-y-auto rounded-lg border bg-muted/35 p-3 font-mono text-[11px] leading-relaxed break-all text-muted-foreground">
                                                {activeHandoff.pairingCode}
                                            </div>
                                            <Button
                                                variant="outline"
                                                className="mt-3 w-full"
                                                onClick={() =>
                                                    copy(
                                                        activeHandoff.pairingCode,
                                                    )
                                                }
                                            >
                                                {copied ? <Check /> : <Copy />}
                                                {copied
                                                    ? 'Copied'
                                                    : 'Copy pairing code'}
                                            </Button>
                                        </>
                                    ) : (
                                        <div className="rounded-lg border border-dashed p-5 text-center text-sm text-muted-foreground">
                                            {status === 'preparing'
                                                ? 'Preparing a new secure pairing code…'
                                                : handoff
                                                  ? 'Generate a new code to use manual pairing.'
                                                  : 'Generate a code to reveal the manual pairing option.'}
                                        </div>
                                    )}
                                </CardContent>
                            </Card>

                            <Card className="gap-4">
                                <CardHeader>
                                    <CardTitle className="flex items-center gap-2 text-base">
                                        <ShieldCheck className="size-4 text-emerald-600 dark:text-emerald-400" />
                                        What this grants
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-3 text-sm text-muted-foreground">
                                    <p>
                                        Your phone gets its own encrypted device
                                        credential for this OpenClaw server. The
                                        shared gateway credential is never
                                        placed in the QR code.
                                    </p>
                                    <p>
                                        Pairing is server-wide, so the app can
                                        list and chat with all agents hosted
                                        alongside {agent.name}.
                                    </p>
                                </CardContent>
                            </Card>
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
