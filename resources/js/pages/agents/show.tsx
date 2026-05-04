import { Head, Link, router, usePage } from '@inertiajs/react';
import { useForm } from '@inertiajs/react';
import {
    AlertCircle,
    ArrowLeft,
    Calendar,
    Check,
    ChevronRight,
    CircleCheck,
    CircleDashed,
    Copy,
    Download,
    ExternalLink,
    Eye,
    EyeOff,
    FileText,
    FolderOpen,
    FolderPlus,
    Key,
    Loader2,
    Lock,
    Mail,
    Monitor,
    MessageSquare,
    MoreHorizontal,
    Paperclip,
    Pause,
    Play,
    Plus,
    RefreshCw,
    ScrollText,
    Settings,
    ShieldAlert,
    Trash2,
    Upload,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import ActivityFeed from '@/components/agents/activity-feed';
import AgentAvatar from '@/components/agents/agent-avatar';
import {
    TelegramIcon,
    SlackIcon,
    DiscordIcon,
} from '@/components/agents/channel-icons';
import DebugLogsDialog from '@/components/agents/debug-logs-dialog';
import MemoryBrowser from '@/components/agents/memory-browser';
import StatusBadge from '@/components/agents/status-badge';
import UsageChart from '@/components/agents/usage-chart';
import DeleteConfirmDialog from '@/components/delete-confirm-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useEcho } from '@/hooks/use-echo';
import AppLayout from '@/layouts/app-layout';
import { roleLabels, relativeTime, formatTokens } from '@/lib/agents';
import { cn } from '@/lib/utils';
import type {
    Agent,
    AgentActivity,
    BreadcrumbItem,
    CronJob,
    SharedData,
} from '@/types';

type Tab =
    | 'overview'
    | 'email'
    | 'channels'
    | 'schedules'
    | 'workspace'
    | 'memory'
    | 'browser'
    | 'settings';

type MessageSummary = {
    id: string | number;
    ulid: string;
    from_email: string;
    from_name: string | null;
    subject: string;
    text_body: string | null;
    created_at: string;
};

type MessageDetail = {
    id: string | number;
    ulid: string;
    from_email: string;
    from_name: string | null;
    to_emails: string[];
    cc_emails: string[];
    subject: string;
    text_body: string | null;
    html_body: string | null;
    attachments: {
        id: string | number;
        filename: string;
        mime_type: string;
        size: number;
        url: string;
    }[];
    created_at: string;
};

type PageMeta = {
    current_page: number;
    last_page: number;
};

function RestartGatewayButton() {
    const [open, setOpen] = useState(false);
    const [restarting, setRestarting] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button
                    variant="ghost"
                    size="sm"
                    className="h-7 gap-1.5 text-xs text-muted-foreground"
                >
                    <RefreshCw className="h-3 w-3" />
                    Restart Gateway
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Restart Gateway</DialogTitle>
                    <DialogDescription>
                        This will restart the OpenClaw gateway on your server.
                        All agents will stop responding until the gateway comes
                        back online (usually 10-15 seconds). Active
                        conversations will be interrupted.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="outline">Cancel</Button>
                    </DialogClose>
                    <Button
                        variant="destructive"
                        disabled={restarting}
                        onClick={() => {
                            setRestarting(true);
                            router.post(
                                '/server/restart-gateway',
                                {},
                                {
                                    onFinish: () => {
                                        setRestarting(false);
                                        setOpen(false);
                                    },
                                },
                            );
                        }}
                    >
                        {restarting && (
                            <Loader2 className="h-4 w-4 animate-spin" />
                        )}
                        {restarting ? 'Restarting...' : 'Restart Gateway'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function CopyButton({ text }: { text: string }) {
    const [copied, setCopied] = useState(false);

    function handleCopy() {
        navigator.clipboard.writeText(text);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    }

    return (
        <Button
            variant="ghost"
            size="sm"
            className="h-7 gap-1.5 px-2 text-xs"
            onClick={handleCopy}
        >
            {copied ? (
                <>
                    <Check className="size-3" />
                    Copied
                </>
            ) : (
                <>
                    <Copy className="size-3" />
                    Copy
                </>
            )}
        </Button>
    );
}

function formatDate(dateString: string): string {
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });
}

function formatShortDate(dateString: string): string {
    const date = new Date(dateString);
    const now = new Date();
    const diffMs = now.getTime() - date.getTime();
    const diffDays = Math.floor(diffMs / (1000 * 60 * 60 * 24));

    if (diffDays === 0) {
        return date.toLocaleTimeString('en-US', {
            hour: 'numeric',
            minute: '2-digit',
        });
    }
    if (diffDays < 7) {
        return date.toLocaleDateString('en-US', { weekday: 'short' });
    }
    return date.toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
    });
}

function formatFileSize(bytes: number): string {
    if (bytes >= 1_000_000) return `${(bytes / 1_000_000).toFixed(1)} MB`;
    if (bytes >= 1_000) return `${(bytes / 1_000).toFixed(1)} KB`;
    return `${bytes} B`;
}

function senderName(msg: {
    from_name: string | null;
    from_email: string;
}): string {
    return msg.from_name || msg.from_email.split('@')[0];
}

function senderInitial(msg: {
    from_name: string | null;
    from_email: string;
}): string {
    const name = senderName(msg);
    return name.charAt(0).toUpperCase();
}

// ─── Email Tab (two-column layout) ───────────────────────────────

type EmailFilter = 'all' | 'received' | 'sent';

function EmailTab({ agent }: { agent: Agent }) {
    const emailAddress = agent.email_connection?.email_address;
    const [messages, setMessages] = useState<MessageSummary[]>([]);
    const [meta, setMeta] = useState<PageMeta | null>(null);
    const [selectedMessage, setSelectedMessage] =
        useState<MessageDetail | null>(null);
    const [selectedUlid, setSelectedUlid] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);
    const [messageLoading, setMessageLoading] = useState(false);
    const [error, setError] = useState('');
    const [page, setPage] = useState(1);
    const [filter, setFilter] = useState<EmailFilter>('all');

    const filteredMessages = messages.filter((msg) => {
        if (filter === 'all') return true;
        const isSent = msg.from_email === emailAddress;
        return filter === 'sent' ? isSent : !isSent;
    });

    const fetchMessages = useCallback(
        async (p: number = 1) => {
            setLoading(true);
            setError('');
            try {
                const res = await fetch(`/agents/${agent.id}/inbox?page=${p}`, {
                    headers: { Accept: 'application/json' },
                });
                const data = await res.json();
                if (!res.ok) {
                    setError(data.error || 'Failed to fetch messages.');
                    return;
                }
                setMessages(data.data ?? []);
                setMeta(data.meta ?? null);
                setPage(p);
            } catch {
                setError('Failed to connect to server.');
            } finally {
                setLoading(false);
            }
        },
        [agent.id],
    );

    const fetchMessage = useCallback(
        async (ulid: string) => {
            setSelectedUlid(ulid);
            setMessageLoading(true);
            setError('');
            try {
                const res = await fetch(`/agents/${agent.id}/inbox/${ulid}`, {
                    headers: { Accept: 'application/json' },
                });
                const data = await res.json();
                if (!res.ok) {
                    setError(data.error || 'Failed to fetch message.');
                    return;
                }
                setSelectedMessage(data.data ?? null);
            } catch {
                setError('Failed to connect to server.');
            } finally {
                setMessageLoading(false);
            }
        },
        [agent.id],
    );

    useEffect(() => {
        if (emailAddress) {
            fetchMessages(1);
        }
    }, [emailAddress, fetchMessages]);

    // Real-time email updates via Reverb
    useEcho<{ agent_id: string }>(
        `team.${agent.team_id}`,
        '.agent.email.received',
        (data) => {
            if (data.agent_id === agent.id && emailAddress) {
                fetchMessages(1);
            }
        },
    );

    if (!emailAddress) {
        return (
            <div className="flex flex-1 flex-col items-center justify-center py-16 text-center">
                <div className="flex size-12 items-center justify-center rounded-full bg-muted">
                    <Mail className="size-6 text-muted-foreground" />
                </div>
                <h3 className="mt-4 text-sm font-medium">
                    Email not configured
                </h3>
                <p className="mt-1 text-sm text-muted-foreground">
                    This agent doesn't have an email address yet.
                </p>
            </div>
        );
    }

    return (
        <div className="flex min-h-0 flex-1 flex-col overflow-hidden border-t md:flex-row">
            {/* ─── Left: Message list ─── */}
            <div
                className={cn(
                    'flex w-full flex-col md:w-[340px] md:border-r lg:w-[380px]',
                    selectedMessage ? 'hidden md:flex' : 'flex',
                )}
            >
                {/* List header */}
                <div className="flex items-center justify-between px-4 py-3">
                    <div className="min-w-0">
                        <p className="text-sm font-medium">Inbox</p>
                        <div className="flex items-center gap-1">
                            <p className="truncate text-xs text-muted-foreground">
                                {emailAddress}
                            </p>
                            <CopyButton text={emailAddress} />
                        </div>
                    </div>
                    <Button
                        variant="ghost"
                        size="icon"
                        className="size-8 shrink-0"
                        disabled={loading}
                        onClick={() => fetchMessages(page)}
                    >
                        <RefreshCw
                            className={cn(
                                'size-3.5',
                                loading && 'animate-spin',
                            )}
                        />
                    </Button>
                </div>

                {/* Filter tabs */}
                <div className="flex gap-1 border-b px-4 pb-2">
                    {(['all', 'received', 'sent'] as const).map((f) => (
                        <button
                            key={f}
                            type="button"
                            onClick={() => setFilter(f)}
                            className={cn(
                                'rounded-md px-2.5 py-1 text-xs font-medium transition-colors',
                                filter === f
                                    ? 'bg-primary text-primary-foreground'
                                    : 'text-muted-foreground hover:bg-muted hover:text-foreground',
                            )}
                        >
                            {f === 'all'
                                ? 'All'
                                : f === 'received'
                                  ? 'Received'
                                  : 'Sent'}
                        </button>
                    ))}
                </div>

                {/* Message list */}
                <div className="flex-1 overflow-y-auto">
                    {loading && filteredMessages.length === 0 && !error ? (
                        <div className="flex items-center justify-center py-16">
                            <Loader2 className="size-5 animate-spin text-muted-foreground" />
                        </div>
                    ) : error && !selectedMessage ? (
                        <div className="p-4">
                            <div className="rounded-lg border border-destructive/50 bg-destructive/10 p-3">
                                <p className="text-sm text-destructive">
                                    {error}
                                </p>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    className="mt-2"
                                    onClick={() => fetchMessages(page)}
                                >
                                    Retry
                                </Button>
                            </div>
                        </div>
                    ) : filteredMessages.length === 0 ? (
                        <div className="flex flex-col items-center justify-center py-16 text-center">
                            <Mail className="mb-2 size-6 text-muted-foreground" />
                            <p className="text-sm font-medium">
                                {filter === 'all'
                                    ? 'No emails yet'
                                    : filter === 'received'
                                      ? 'No received emails'
                                      : 'No sent emails'}
                            </p>
                            <p className="mt-0.5 text-xs text-muted-foreground">
                                {filter === 'all'
                                    ? 'Emails sent to this agent will appear here.'
                                    : filter === 'received'
                                      ? 'Inbound emails will appear here.'
                                      : 'Emails sent by this agent will appear here.'}
                            </p>
                        </div>
                    ) : (
                        <div className="p-2">
                            {filteredMessages.map((msg) => (
                                <button
                                    key={msg.id}
                                    type="button"
                                    className={cn(
                                        'flex w-full items-start gap-3 rounded-lg px-3 py-2.5 text-left transition-colors',
                                        selectedUlid === msg.ulid
                                            ? 'bg-primary/10 dark:bg-primary/15'
                                            : 'hover:bg-muted/60',
                                    )}
                                    onClick={() => fetchMessage(msg.ulid)}
                                >
                                    <div
                                        className={cn(
                                            'mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full text-xs font-medium',
                                            selectedUlid === msg.ulid
                                                ? 'bg-primary text-primary-foreground'
                                                : 'bg-muted text-muted-foreground',
                                        )}
                                    >
                                        {senderInitial(msg)}
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <div className="flex items-baseline justify-between gap-2">
                                            <p className="truncate text-sm font-medium">
                                                {senderName(msg)}
                                            </p>
                                            <span className="shrink-0 text-[11px] text-muted-foreground tabular-nums">
                                                {formatShortDate(
                                                    msg.created_at,
                                                )}
                                            </span>
                                        </div>
                                        <p className="truncate text-[13px] text-foreground/80">
                                            {msg.subject || '(no subject)'}
                                        </p>
                                        {msg.text_body && (
                                            <p className="mt-0.5 truncate text-xs text-muted-foreground">
                                                {msg.text_body.slice(0, 80)}
                                            </p>
                                        )}
                                    </div>
                                </button>
                            ))}
                        </div>
                    )}
                </div>

                {/* Pagination */}
                {meta && meta.last_page > 1 && (
                    <div className="flex items-center justify-between border-t px-4 py-2">
                        <Button
                            variant="ghost"
                            size="sm"
                            className="h-7 px-2 text-xs"
                            disabled={page <= 1 || loading}
                            onClick={() => fetchMessages(page - 1)}
                        >
                            Previous
                        </Button>
                        <span className="text-xs text-muted-foreground tabular-nums">
                            {meta.current_page} / {meta.last_page}
                        </span>
                        <Button
                            variant="ghost"
                            size="sm"
                            className="h-7 px-2 text-xs"
                            disabled={page >= meta.last_page || loading}
                            onClick={() => fetchMessages(page + 1)}
                        >
                            Next
                        </Button>
                    </div>
                )}
            </div>

            {/* ─── Right: Message detail ─── */}
            <div
                className={cn(
                    'flex flex-1 flex-col overflow-hidden bg-background',
                    !selectedMessage ? 'hidden md:flex' : 'flex',
                )}
            >
                {messageLoading && !selectedMessage ? (
                    <div className="flex flex-1 items-center justify-center">
                        <Loader2 className="size-5 animate-spin text-muted-foreground" />
                    </div>
                ) : selectedMessage ? (
                    <div className="flex flex-1 flex-col overflow-hidden">
                        {/* Mobile back */}
                        <div className="border-b px-5 py-3 md:hidden">
                            <button
                                type="button"
                                onClick={() => {
                                    setSelectedMessage(null);
                                    setSelectedUlid(null);
                                }}
                                className="flex items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
                            >
                                <ArrowLeft className="size-3.5" />
                                Back
                            </button>
                        </div>

                        {/* Scrollable message content */}
                        <div className="flex-1 overflow-y-auto">
                            <div className="px-5 py-5 md:px-8 md:py-6">
                                {/* Subject */}
                                <h3 className="text-lg leading-snug font-semibold">
                                    {selectedMessage.subject || '(no subject)'}
                                </h3>

                                {/* Sender card */}
                                <div className="mt-5 flex items-start gap-3">
                                    <div className="flex size-9 shrink-0 items-center justify-center rounded-full bg-primary text-xs font-medium text-primary-foreground">
                                        {senderInitial(selectedMessage)}
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <div className="flex items-baseline justify-between gap-3">
                                            <p className="text-sm font-medium">
                                                {senderName(selectedMessage)}
                                            </p>
                                            <span className="shrink-0 text-xs text-muted-foreground">
                                                {formatDate(
                                                    selectedMessage.created_at,
                                                )}
                                            </span>
                                        </div>
                                        <p className="text-xs text-muted-foreground">
                                            to{' '}
                                            {selectedMessage.to_emails?.length >
                                            0
                                                ? selectedMessage.to_emails.join(
                                                      ', ',
                                                  )
                                                : emailAddress}
                                        </p>
                                        {selectedMessage.cc_emails?.length >
                                            0 && (
                                            <p className="text-xs text-muted-foreground">
                                                cc{' '}
                                                {selectedMessage.cc_emails.join(
                                                    ', ',
                                                )}
                                            </p>
                                        )}
                                    </div>
                                </div>

                                {/* Divider */}
                                <div className="my-5 border-b" />

                                {/* Body */}
                                <div className="text-sm leading-relaxed text-foreground/90">
                                    <pre className="font-sans whitespace-pre-wrap">
                                        {selectedMessage.text_body ||
                                            '(no content)'}
                                    </pre>
                                </div>

                                {/* Attachments */}
                                {selectedMessage.attachments?.length > 0 && (
                                    <div className="mt-6 border-t pt-4">
                                        <p className="flex items-center gap-1.5 text-xs font-medium text-muted-foreground">
                                            <Paperclip className="size-3" />
                                            {
                                                selectedMessage.attachments
                                                    .length
                                            }{' '}
                                            {selectedMessage.attachments
                                                .length === 1
                                                ? 'attachment'
                                                : 'attachments'}
                                        </p>
                                        <div className="mt-2 flex flex-wrap gap-2">
                                            {selectedMessage.attachments.map(
                                                (att) => (
                                                    <a
                                                        key={att.id}
                                                        href={att.url}
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        className="inline-flex items-center gap-2 rounded-lg border px-3 py-2 text-sm transition-colors hover:bg-muted/50"
                                                    >
                                                        <Paperclip className="size-3.5 text-muted-foreground" />
                                                        <span className="truncate">
                                                            {att.filename}
                                                        </span>
                                                        <span className="text-xs text-muted-foreground">
                                                            {formatFileSize(
                                                                att.size,
                                                            )}
                                                        </span>
                                                    </a>
                                                ),
                                            )}
                                        </div>
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>
                ) : (
                    <div className="flex flex-1 items-center justify-center">
                        <div className="text-center">
                            <div className="mx-auto flex size-12 items-center justify-center rounded-full bg-muted/50">
                                <Mail className="size-5 text-muted-foreground/50" />
                            </div>
                            <p className="mt-3 text-sm text-muted-foreground">
                                Select a message to read
                            </p>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
}

// ─── Other components ────────────────────────────────────────────

function ErrorBanner({ agent }: { agent: Agent }) {
    const form = useForm({});

    if (agent.status !== 'error') {
        return null;
    }

    return (
        <div className="mb-3 rounded-lg border border-destructive/50 bg-destructive/10 p-4">
            <div className="flex items-center justify-between gap-4">
                <div className="flex items-center gap-3">
                    <AlertCircle className="size-5 shrink-0 text-destructive" />
                    <div>
                        <p className="text-sm font-medium text-destructive">
                            Deployment failed
                        </p>
                        <p className="text-sm text-muted-foreground">
                            Something went wrong while deploying this agent. You
                            can retry the deployment or delete the agent and
                            start over.
                        </p>
                    </div>
                </div>
                <Button
                    variant="outline"
                    size="sm"
                    className="shrink-0 gap-2"
                    disabled={form.processing}
                    onClick={() => form.post(`/agents/${agent.id}/retry`)}
                >
                    <RefreshCw
                        className={cn(
                            'size-3.5',
                            form.processing && 'animate-spin',
                        )}
                    />
                    {form.processing ? 'Retrying...' : 'Retry'}
                </Button>
            </div>
        </div>
    );
}

function AutoTopUpNudge({ agent }: { agent: Agent }) {
    const { wallet } = usePage<SharedData>().props;
    if (!wallet || wallet.auto_topup_enabled) return null;
    if (agent.auth_provider === 'chatgpt') return null;

    const lowBalance = wallet.balance_cents < 300;

    return (
        <Link
            href="/billing"
            className={cn(
                'flex items-start gap-3 rounded-lg border p-4 transition-colors hover:bg-muted/50',
                lowBalance
                    ? 'border-destructive/50 bg-destructive/5'
                    : 'border-amber-500/30 bg-amber-500/5',
            )}
        >
            <ShieldAlert
                className={cn(
                    'mt-0.5 size-5 shrink-0',
                    lowBalance ? 'text-destructive' : 'text-amber-500',
                )}
            />
            <div>
                <p className="text-sm font-medium">
                    {lowBalance ? 'Credits running low' : 'Enable auto top-up'}
                </p>
                <p className="mt-0.5 text-xs text-muted-foreground">
                    {lowBalance
                        ? 'This agent will stop responding when credits hit zero. Enable auto top-up to prevent interruptions.'
                        : 'Without auto top-up, this agent will stop mid-conversation when credits run out.'}
                </p>
            </div>
        </Link>
    );
}

function OverviewTab({ agent }: { agent: Agent }) {
    const [showPassword, setShowPassword] = useState(false);

    return (
        <div className="space-y-8">
            <AutoTopUpNudge agent={agent} />

            {agent.status === 'active' && (
                <section>
                    <div className="flex items-center justify-between">
                        <h3 className="text-sm font-medium">Activity</h3>
                        {agent.stats_synced_at && (
                            <span className="text-xs text-muted-foreground">
                                Updated {relativeTime(agent.stats_synced_at)}
                            </span>
                        )}
                    </div>
                    <div className="mt-3 grid grid-cols-2 gap-x-8 gap-y-4 sm:grid-cols-4">
                        <div>
                            <p className="text-2xl font-semibold tabular-nums">
                                {agent.stats_total_sessions}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                Sessions
                            </p>
                        </div>
                        <div>
                            <p className="text-2xl font-semibold tabular-nums">
                                {agent.stats_total_messages}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                Messages
                            </p>
                        </div>
                        <div>
                            <p className="text-2xl font-semibold tabular-nums">
                                {formatTokens(
                                    agent.stats_tokens_input +
                                        agent.stats_tokens_output,
                                )}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                Tokens used
                            </p>
                        </div>
                        <div>
                            <p className="text-2xl font-semibold tabular-nums">
                                {relativeTime(agent.stats_last_active_at)}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                Last active
                            </p>
                        </div>
                    </div>
                </section>
            )}

            {agent.status === 'active' && (
                <section>
                    <h3 className="text-sm font-medium">Token Usage</h3>
                    <div className="mt-3">
                        <UsageChart agentId={agent.id} embedded />
                    </div>
                </section>
            )}

            <section>
                <h3 className="text-sm font-medium">Details</h3>
                <dl className="mt-3 grid grid-cols-2 gap-x-8 gap-y-3 text-sm">
                    {agent.server && (
                        <>
                            <dt className="text-muted-foreground">Server</dt>
                            <dd>
                                <Badge
                                    variant="outline"
                                    className="gap-1.5 font-normal"
                                >
                                    <span className="inline-block h-1.5 w-1.5 rounded-full bg-green-500" />
                                    {agent.server.name} ({agent.server.status})
                                </Badge>
                                <RestartGatewayButton />
                            </dd>
                        </>
                    )}
                    <dt className="text-muted-foreground">Model</dt>
                    <dd className="font-medium">
                        {agent.model_primary ?? 'Not set'}
                        {agent.chatgpt_email && (
                            <span
                                className="ml-2 rounded bg-emerald-100 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300"
                                title={`Billed via ChatGPT subscription (${agent.chatgpt_email})`}
                            >
                                via ChatGPT
                                {agent.chatgpt_plan_type
                                    ? ` ${agent.chatgpt_plan_type}`
                                    : ''}
                            </span>
                        )}
                    </dd>
                    <dt className="text-muted-foreground">Last synced</dt>
                    <dd className="font-medium">
                        {relativeTime(agent.last_synced_at)}
                    </dd>
                    <dt className="text-muted-foreground">Created</dt>
                    <dd className="font-medium">
                        {new Date(agent.created_at).toLocaleDateString(
                            'en-US',
                            {
                                month: 'short',
                                day: 'numeric',
                                year: 'numeric',
                            },
                        )}
                    </dd>
                </dl>
            </section>

            {/* Agent Credentials */}
            {(agent.email_connection?.email_address ||
                agent.default_password) && (
                <section className="rounded-lg border border-border p-4">
                    <h3 className="mb-3 text-sm font-semibold">
                        Agent Credentials
                    </h3>
                    <p className="mb-4 text-xs text-muted-foreground">
                        Your agent uses these credentials when signing up for
                        services.
                    </p>
                    <div className="space-y-3">
                        {agent.email_connection?.email_address && (
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-xs text-muted-foreground">
                                        Email
                                    </p>
                                    <p className="font-mono text-sm">
                                        {agent.email_connection.email_address}
                                    </p>
                                </div>
                                <CopyButton
                                    text={agent.email_connection.email_address}
                                />
                            </div>
                        )}
                        {agent.default_password && (
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-xs text-muted-foreground">
                                        Password
                                    </p>
                                    <p className="font-mono text-sm">
                                        {showPassword
                                            ? agent.default_password
                                            : '\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022'}
                                    </p>
                                </div>
                                <div className="flex items-center gap-2">
                                    <button
                                        type="button"
                                        onClick={() =>
                                            setShowPassword(!showPassword)
                                        }
                                        className="text-muted-foreground hover:text-foreground"
                                    >
                                        {showPassword ? (
                                            <EyeOff className="size-4" />
                                        ) : (
                                            <Eye className="size-4" />
                                        )}
                                    </button>
                                    <CopyButton text={agent.default_password} />
                                </div>
                            </div>
                        )}
                    </div>
                </section>
            )}

            {/* Tools */}
            {agent.tools && agent.tools.length > 0 && (
                <section className="rounded-lg border border-border p-4">
                    <h3 className="mb-3 text-sm font-semibold">Tools</h3>
                    <div className="space-y-2">
                        {agent.tools.map((tool) => (
                            <div
                                key={tool.id}
                                className="flex items-center justify-between text-sm"
                            >
                                <div className="flex items-center gap-2">
                                    <span>{tool.name}</span>
                                    {tool.url && (
                                        <span className="text-xs text-muted-foreground">
                                            {tool.url}
                                        </span>
                                    )}
                                </div>
                                <span
                                    className={cn(
                                        'text-xs font-medium',
                                        tool.status === 'active'
                                            ? 'text-green-500'
                                            : 'text-muted-foreground',
                                    )}
                                >
                                    {tool.status === 'active'
                                        ? 'Connected'
                                        : 'Pending'}
                                </span>
                            </div>
                        ))}
                    </div>
                </section>
            )}

            {/* Installed Skills */}
            {agent.skills && agent.skills.length > 0 && (
                <section className="rounded-lg border border-border p-4">
                    <div className="mb-3 flex items-center justify-between">
                        <h3 className="text-sm font-semibold">
                            Installed Skills
                        </h3>
                        <Link
                            href="/skills"
                            className="text-xs text-muted-foreground hover:text-foreground"
                        >
                            Browse Skills
                        </Link>
                    </div>
                    <div className="space-y-2">
                        {agent.skills.map((skill) => (
                            <div
                                key={skill.id}
                                className="flex items-center justify-between text-sm"
                            >
                                <div className="flex items-center gap-2">
                                    <Link
                                        href={`/skills/${skill.slug}`}
                                        className="font-medium hover:underline"
                                    >
                                        {skill.name}
                                    </Link>
                                    {skill.pivot?.installed_version && (
                                        <span className="text-[10px] text-muted-foreground">
                                            v{skill.pivot.installed_version}
                                        </span>
                                    )}
                                </div>
                                <div className="flex items-center gap-2">
                                    {skill.pivot?.installed_at && (
                                        <span className="text-[10px] text-muted-foreground">
                                            {new Date(
                                                skill.pivot.installed_at,
                                            ).toLocaleDateString('en-US', {
                                                month: 'short',
                                                day: 'numeric',
                                            })}
                                        </span>
                                    )}
                                    <button
                                        type="button"
                                        onClick={() =>
                                            router.delete(
                                                `/skills/${skill.slug}/undeploy/${agent.id}`,
                                                { preserveScroll: true },
                                            )
                                        }
                                        className="text-xs text-destructive hover:underline"
                                    >
                                        Remove
                                    </button>
                                </div>
                            </div>
                        ))}
                    </div>
                </section>
            )}
        </div>
    );
}

function ChannelStatusDot({ connected }: { connected: boolean }) {
    return connected ? (
        <CircleCheck className="size-4 text-green-500" />
    ) : (
        <CircleDashed className="size-4 text-muted-foreground/50" />
    );
}

function MaskedToken({ token }: { token: string | null }) {
    if (!token) return null;

    return (
        <div className="mt-1.5 flex items-center gap-1.5">
            <Key className="size-3 text-muted-foreground/70" />
            <code className="rounded bg-muted px-1.5 py-0.5 font-mono text-[11px] text-muted-foreground">
                {token}
            </code>
        </div>
    );
}

function ChannelsTab({ agent }: { agent: Agent }) {
    const tg = agent.telegram_connection;
    const slack = agent.slack_connection;
    const discord = agent.discord_connection;

    const tgConnected = tg?.status === 'connected';
    const slackConnected = slack?.status === 'connected';
    const discordConnected = discord?.status === 'connected';
    const [resyncing, setResyncing] = useState(false);
    const hasAnyChannel = true; // Web chat is always available

    return (
        <div>
            <div className="mb-5">
                <h3 className="text-sm font-medium">Chat with {agent.name}</h3>
                <p className="mt-0.5 text-sm text-muted-foreground">
                    Channels where you can reach this agent.
                </p>
            </div>

            <div className="space-y-3">
                {/* Web Chat — always available */}
                <div className="flex items-start justify-between rounded-lg border border-primary/20 bg-primary/[0.03] px-4 py-3.5">
                    <div className="flex items-start gap-3">
                        <div className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary/10">
                            <MessageSquare className="size-4 text-primary" />
                        </div>
                        <div>
                            <div className="flex items-center gap-2">
                                <p className="text-sm font-medium">Web Chat</p>
                                <span className="inline-flex items-center gap-1 rounded-full bg-emerald-500/10 px-2 py-0.5 text-[10px] font-medium text-emerald-600 dark:text-emerald-400">
                                    <span className="size-1.5 rounded-full bg-emerald-500" />
                                    Always on
                                </span>
                            </div>
                            <p className="mt-0.5 text-sm text-muted-foreground">
                                Chat directly with your agent from the browser.
                                No setup needed.
                            </p>
                        </div>
                    </div>
                    <Button
                        variant="outline"
                        size="sm"
                        className="h-8 gap-1.5 text-xs"
                        asChild
                    >
                        <Link href={`/agents/${agent.id}/chat`}>
                            <MessageSquare className="size-3" />
                            Open Chat
                        </Link>
                    </Button>
                </div>

                {/* Telegram */}
                <div className="flex items-start justify-between rounded-lg border px-4 py-3.5">
                    <div className="flex items-start gap-3">
                        <div className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-muted">
                            <TelegramIcon className="size-4 text-muted-foreground" />
                        </div>
                        <div>
                            <div className="flex items-center gap-2">
                                <p className="text-sm font-medium">Telegram</p>
                                <ChannelStatusDot connected={tgConnected} />
                            </div>
                            {tgConnected ? (
                                <>
                                    {tg!.bot_username && (
                                        <p className="mt-0.5 text-sm text-muted-foreground">
                                            @{tg!.bot_username}
                                        </p>
                                    )}
                                    <MaskedToken token={tg!.bot_token_masked} />
                                </>
                            ) : (
                                <p className="mt-0.5 text-sm text-muted-foreground">
                                    Not connected
                                </p>
                            )}
                        </div>
                    </div>
                    <div className="flex items-center gap-2">
                        {tgConnected && tg!.bot_username && (
                            <Button
                                variant="ghost"
                                size="sm"
                                className="h-8 gap-1.5 text-xs"
                                asChild
                            >
                                <a
                                    href={`https://t.me/${tg!.bot_username}`}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    <ExternalLink className="size-3" />
                                    Open
                                </a>
                            </Button>
                        )}
                        <Button
                            variant="outline"
                            size="sm"
                            className="h-8 text-xs"
                            asChild
                        >
                            <Link href={`/agents/${agent.id}/telegram`}>
                                {tgConnected ? 'Manage' : 'Set up'}
                            </Link>
                        </Button>
                    </div>
                </div>

                {/* Slack */}
                <div className="flex items-start justify-between rounded-lg border px-4 py-3.5">
                    <div className="flex items-start gap-3">
                        <div className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-muted">
                            <SlackIcon className="size-4 text-muted-foreground" />
                        </div>
                        <div>
                            <div className="flex items-center gap-2">
                                <p className="text-sm font-medium">Slack</p>
                                <ChannelStatusDot connected={slackConnected} />
                            </div>
                            {slackConnected ? (
                                <p className="mt-0.5 text-sm text-muted-foreground">
                                    {slack!.slack_app_id
                                        ? `App ${slack!.slack_app_id}`
                                        : 'Connected'}
                                </p>
                            ) : (
                                <p className="mt-0.5 text-sm text-muted-foreground">
                                    Not connected
                                </p>
                            )}
                        </div>
                    </div>
                    <Button
                        variant="outline"
                        size="sm"
                        className="h-8 text-xs"
                        asChild
                    >
                        <Link href={`/agents/${agent.id}/slack`}>
                            {slackConnected ? 'Manage' : 'Set up'}
                        </Link>
                    </Button>
                </div>

                {/* Discord */}
                <div className="flex items-start justify-between rounded-lg border px-4 py-3.5">
                    <div className="flex items-start gap-3">
                        <div className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-muted">
                            <DiscordIcon className="size-4 text-muted-foreground" />
                        </div>
                        <div>
                            <div className="flex items-center gap-2">
                                <p className="text-sm font-medium">Discord</p>
                                <ChannelStatusDot
                                    connected={discordConnected}
                                />
                            </div>
                            {discordConnected ? (
                                <>
                                    <p className="mt-0.5 text-sm text-muted-foreground">
                                        {discord!.bot_username
                                            ? `@${discord!.bot_username}`
                                            : 'Connected'}
                                    </p>
                                    <MaskedToken
                                        token={discord!.token_masked}
                                    />
                                </>
                            ) : (
                                <p className="mt-0.5 text-sm text-muted-foreground">
                                    Not connected
                                </p>
                            )}
                        </div>
                    </div>
                    <Button
                        variant="outline"
                        size="sm"
                        className="h-8 text-xs"
                        asChild
                    >
                        <Link href={`/agents/${agent.id}/discord`}>
                            {discordConnected ? 'Manage' : 'Set up'}
                        </Link>
                    </Button>
                </div>
            </div>

            <div className="mt-4 flex items-center gap-2">
                <Button
                    variant="ghost"
                    size="sm"
                    className="h-8 gap-1.5 text-xs text-muted-foreground"
                    asChild
                >
                    <Link href={`/agents/${agent.id}/channels`}>
                        <Plus className="size-3.5" />
                        Connect another channel
                    </Link>
                </Button>
                {hasAnyChannel && agent.status === 'active' && agent.server && (
                    <Button
                        variant="ghost"
                        size="sm"
                        className="h-8 gap-1.5 text-xs text-muted-foreground"
                        disabled={resyncing}
                        onClick={() => {
                            setResyncing(true);
                            router.post(
                                `/agents/${agent.id}/resync-channels`,
                                {},
                                {
                                    preserveScroll: true,
                                    onFinish: () => setResyncing(false),
                                },
                            );
                        }}
                    >
                        {resyncing ? (
                            <Loader2 className="size-3.5 animate-spin" />
                        ) : (
                            <RefreshCw className="size-3.5" />
                        )}
                        Re-sync channels
                    </Button>
                )}
            </div>
        </div>
    );
}

// ─── Schedule helpers ─────────────────────────────────────────────

type FreqType = 'minutes' | 'hourly' | 'daily' | 'weekly';

const DAYS_OF_WEEK = [
    'Monday',
    'Tuesday',
    'Wednesday',
    'Thursday',
    'Friday',
    'Saturday',
    'Sunday',
] as const;
const MINUTE_INTERVALS = [5, 10, 15, 30] as const;
const HOUR_INTERVALS = [1, 2, 3, 4, 6, 8, 12] as const;

function buildEveryValue(
    type: FreqType,
    minuteInterval: number,
    hourInterval: number,
): string {
    if (type === 'minutes') return `${minuteInterval}m`;
    if (type === 'hourly') return `${hourInterval}h`;
    if (type === 'daily') return '24h';
    return '168h'; // weekly
}

function describeSchedule(
    type: FreqType,
    minuteInterval: number,
    hourInterval: number,
    day: string,
    hour: number,
    minute: number,
): string {
    const time = `${hour.toString().padStart(2, '0')}:${minute.toString().padStart(2, '0')}`;
    if (type === 'minutes') return `Every ${minuteInterval} minutes`;
    if (type === 'hourly')
        return hourInterval === 1
            ? 'Every hour'
            : `Every ${hourInterval} hours`;
    if (type === 'daily') return `Every day at ${time}`;
    return `Every ${day} at ${time}`;
}

function formatIntervalHuman(ms: number): string {
    const minutes = Math.round(ms / 60000);
    if (minutes < 60) return `Every ${minutes} min`;
    const hours = Math.round(minutes / 60);
    if (hours === 1) return 'Every hour';
    if (hours < 24) return `Every ${hours} hours`;
    if (hours === 24) return 'Daily';
    if (hours === 168) return 'Weekly';
    const days = Math.round(hours / 24);
    return `Every ${days} days`;
}

function csrfHeader(): Record<string, string> {
    return {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-XSRF-TOKEN': decodeURIComponent(
            document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '',
        ),
    };
}

/** Parses an everyMs value back into frequency editor state. */
function parseEveryMs(ms: number): {
    type: FreqType;
    minuteInterval: number;
    hourInterval: number;
} {
    const minutes = Math.round(ms / 60000);
    if (minutes < 60)
        return { type: 'minutes', minuteInterval: minutes, hourInterval: 1 };
    const hours = Math.round(minutes / 60);
    if (hours >= 168)
        return { type: 'weekly', minuteInterval: 5, hourInterval: 1 };
    if (hours >= 24)
        return { type: 'daily', minuteInterval: 5, hourInterval: 1 };
    return { type: 'hourly', minuteInterval: 5, hourInterval: hours };
}

function FrequencyEditor({
    type,
    setType,
    minuteInterval,
    setMinuteInterval,
    hourInterval,
    setHourInterval,
    day,
    setDay,
    hour,
    setHour,
    minute,
    setMinute,
}: {
    type: FreqType;
    setType: (t: FreqType) => void;
    minuteInterval: number;
    setMinuteInterval: (n: number) => void;
    hourInterval: number;
    setHourInterval: (n: number) => void;
    day: string;
    setDay: (d: string) => void;
    hour: number;
    setHour: (n: number) => void;
    minute: number;
    setMinute: (n: number) => void;
}) {
    const typeOptions: { value: FreqType; label: string }[] = [
        { value: 'minutes', label: 'Minutes' },
        { value: 'hourly', label: 'Hourly' },
        { value: 'daily', label: 'Daily' },
        { value: 'weekly', label: 'Weekly' },
    ];

    return (
        <div className="space-y-3">
            {/* Type selector */}
            <div className="flex rounded-lg border p-1">
                {typeOptions.map((opt) => (
                    <button
                        key={opt.value}
                        type="button"
                        className={cn(
                            'flex-1 rounded-md px-3 py-1.5 text-xs font-medium transition-colors',
                            type === opt.value
                                ? 'bg-primary text-primary-foreground shadow-sm'
                                : 'text-muted-foreground hover:text-foreground',
                        )}
                        onClick={() => setType(opt.value)}
                    >
                        {opt.label}
                    </button>
                ))}
            </div>

            {/* Contextual controls */}
            {type === 'minutes' && (
                <div className="flex items-center gap-2 text-sm">
                    <span className="text-muted-foreground">Every</span>
                    <select
                        value={minuteInterval}
                        onChange={(e) =>
                            setMinuteInterval(Number(e.target.value))
                        }
                        className="h-8 rounded-md border border-input bg-background px-2 text-sm"
                    >
                        {MINUTE_INTERVALS.map((m) => (
                            <option key={m} value={m}>
                                {m}
                            </option>
                        ))}
                    </select>
                    <span className="text-muted-foreground">minutes</span>
                </div>
            )}

            {type === 'hourly' && (
                <div className="flex items-center gap-2 text-sm">
                    <span className="text-muted-foreground">Every</span>
                    <select
                        value={hourInterval}
                        onChange={(e) =>
                            setHourInterval(Number(e.target.value))
                        }
                        className="h-8 rounded-md border border-input bg-background px-2 text-sm"
                    >
                        {HOUR_INTERVALS.map((h) => (
                            <option key={h} value={h}>
                                {h}
                            </option>
                        ))}
                    </select>
                    <span className="text-muted-foreground">
                        {hourInterval === 1 ? 'hour' : 'hours'}
                    </span>
                </div>
            )}

            {type === 'daily' && (
                <div className="flex items-center gap-2 text-sm">
                    <span className="text-muted-foreground">Every day at</span>
                    <select
                        value={hour}
                        onChange={(e) => setHour(Number(e.target.value))}
                        className="h-8 rounded-md border border-input bg-background px-2 text-sm"
                    >
                        {Array.from({ length: 24 }, (_, i) => (
                            <option key={i} value={i}>
                                {i.toString().padStart(2, '0')}
                            </option>
                        ))}
                    </select>
                    <span className="text-muted-foreground">:</span>
                    <select
                        value={minute}
                        onChange={(e) => setMinute(Number(e.target.value))}
                        className="h-8 rounded-md border border-input bg-background px-2 text-sm"
                    >
                        {[0, 15, 30, 45].map((m) => (
                            <option key={m} value={m}>
                                {m.toString().padStart(2, '0')}
                            </option>
                        ))}
                    </select>
                </div>
            )}

            {type === 'weekly' && (
                <div className="space-y-2">
                    <div className="flex items-center gap-2 text-sm">
                        <span className="text-muted-foreground">Every</span>
                        <select
                            value={day}
                            onChange={(e) => setDay(e.target.value)}
                            className="h-8 rounded-md border border-input bg-background px-2 text-sm"
                        >
                            {DAYS_OF_WEEK.map((d) => (
                                <option key={d} value={d}>
                                    {d}
                                </option>
                            ))}
                        </select>
                        <span className="text-muted-foreground">at</span>
                        <select
                            value={hour}
                            onChange={(e) => setHour(Number(e.target.value))}
                            className="h-8 rounded-md border border-input bg-background px-2 text-sm"
                        >
                            {Array.from({ length: 24 }, (_, i) => (
                                <option key={i} value={i}>
                                    {i.toString().padStart(2, '0')}
                                </option>
                            ))}
                        </select>
                        <span className="text-muted-foreground">:</span>
                        <select
                            value={minute}
                            onChange={(e) => setMinute(Number(e.target.value))}
                            className="h-8 rounded-md border border-input bg-background px-2 text-sm"
                        >
                            {[0, 15, 30, 45].map((m) => (
                                <option key={m} value={m}>
                                    {m.toString().padStart(2, '0')}
                                </option>
                            ))}
                        </select>
                    </div>
                </div>
            )}

            {/* Human summary */}
            <p className="text-xs text-muted-foreground">
                {describeSchedule(
                    type,
                    minuteInterval,
                    hourInterval,
                    day,
                    hour,
                    minute,
                )}
            </p>
        </div>
    );
}

function SchedulesSection({ agent }: { agent: Agent }) {
    const [crons, setCrons] = useState<CronJob[]>([]);
    const [loading, setLoading] = useState(true);
    const [dialogOpen, setDialogOpen] = useState(false);
    const [submitting, setSubmitting] = useState(false);
    const [editingId, setEditingId] = useState<string | null>(null);

    // Form state
    const [formName, setFormName] = useState('');
    const [formMessage, setFormMessage] = useState('');
    const [freqType, setFreqType] = useState<FreqType>('daily');
    const [minuteInterval, setMinuteInterval] = useState(5);
    const [hourInterval, setHourInterval] = useState(1);
    const [day, setDay] = useState('Monday');
    const [hour, setHour] = useState(9);
    const [minute, setMinute] = useState(0);

    const fetchCrons = useCallback(() => {
        setLoading(true);
        fetch(`/agents/${agent.id}/schedules`, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        })
            .then((r) => (r.ok ? r.json() : Promise.reject()))
            .then((data) => setCrons(Array.isArray(data) ? data : []))
            .catch(() => setCrons([]))
            .finally(() => setLoading(false));
    }, [agent.id]);

    useEffect(() => {
        let cancelled = false;
        fetch(`/agents/${agent.id}/schedules`, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        })
            .then((r) => (r.ok ? r.json() : Promise.reject()))
            .then((data) => {
                if (!cancelled) {
                    setCrons(Array.isArray(data) ? data : []);
                }
            })
            .catch(() => {
                if (!cancelled) {
                    setCrons([]);
                }
            })
            .finally(() => {
                if (!cancelled) {
                    setLoading(false);
                }
            });
        return () => {
            cancelled = true;
        };
    }, [agent.id]);

    function resetForm() {
        setFormName('');
        setFormMessage('');
        setFreqType('daily');
        setMinuteInterval(5);
        setHourInterval(1);
        setDay('Monday');
        setHour(9);
        setMinute(0);
        setEditingId(null);
    }

    function handleSubmit() {
        setSubmitting(true);
        const every = buildEveryValue(freqType, minuteInterval, hourInterval);
        const name =
            formName ||
            describeSchedule(
                freqType,
                minuteInterval,
                hourInterval,
                day,
                hour,
                minute,
            );
        const url = editingId
            ? `/agents/${agent.id}/schedules/${editingId}`
            : `/agents/${agent.id}/schedules`;

        fetch(url, {
            method: editingId ? 'PATCH' : 'POST',
            headers: csrfHeader(),
            credentials: 'same-origin',
            body: JSON.stringify({ name, every, message: formMessage }),
        })
            .then(() => {
                setDialogOpen(false);
                resetForm();
                fetchCrons();
            })
            .finally(() => setSubmitting(false));
    }

    function handleToggle(cronId: string, enabled: boolean) {
        fetch(`/agents/${agent.id}/schedules/${cronId}/toggle`, {
            method: 'PATCH',
            headers: csrfHeader(),
            credentials: 'same-origin',
            body: JSON.stringify({ enabled }),
        }).then(() => fetchCrons());
    }

    function handleDelete(cronId: string) {
        fetch(`/agents/${agent.id}/schedules/${cronId}`, {
            method: 'DELETE',
            headers: csrfHeader(),
            credentials: 'same-origin',
        }).then(() => fetchCrons());
    }

    function handleRun(cronId: string) {
        fetch(`/agents/${agent.id}/schedules/${cronId}/run`, {
            method: 'POST',
            headers: csrfHeader(),
            credentials: 'same-origin',
        });
    }

    function openEdit(cron: CronJob) {
        const parsed = parseEveryMs(cron.schedule.everyMs);
        setFreqType(parsed.type);
        setMinuteInterval(parsed.minuteInterval);
        setHourInterval(parsed.hourInterval);
        setFormName(cron.name);
        setFormMessage(cron.payload.message);
        setEditingId(cron.id);
        setDialogOpen(true);
    }

    return (
        <div className="space-y-3">
            <div className="flex items-center justify-between">
                <div className="flex items-center gap-2">
                    <Calendar className="size-4 text-muted-foreground" />
                    <p className="text-sm font-medium">Schedules</p>
                </div>
                <Dialog
                    open={dialogOpen}
                    onOpenChange={(open) => {
                        setDialogOpen(open);
                        if (!open) resetForm();
                    }}
                >
                    <DialogTrigger asChild>
                        <Button variant="outline" size="sm" className="gap-1.5">
                            <Plus className="size-3.5" />
                            Add
                        </Button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogTitle>
                            {editingId ? 'Edit Schedule' : 'New Schedule'}
                        </DialogTitle>
                        <DialogDescription>
                            Set when and what this agent should do on a
                            recurring basis.
                        </DialogDescription>

                        <div className="space-y-4">
                            <div>
                                <Label className="mb-2 block">When</Label>
                                <FrequencyEditor
                                    type={freqType}
                                    setType={setFreqType}
                                    minuteInterval={minuteInterval}
                                    setMinuteInterval={setMinuteInterval}
                                    hourInterval={hourInterval}
                                    setHourInterval={setHourInterval}
                                    day={day}
                                    setDay={setDay}
                                    hour={hour}
                                    setHour={setHour}
                                    minute={minute}
                                    setMinute={setMinute}
                                />
                            </div>

                            <div>
                                <Label htmlFor="sched-message">
                                    What should the agent do?
                                </Label>
                                <textarea
                                    id="sched-message"
                                    className="mt-1.5 flex w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-xs"
                                    rows={3}
                                    value={formMessage}
                                    onChange={(e) =>
                                        setFormMessage(e.target.value)
                                    }
                                    placeholder="e.g. Check your email inbox and reply to anything urgent"
                                />
                            </div>

                            <div>
                                <Label htmlFor="sched-name">
                                    Name (optional)
                                </Label>
                                <Input
                                    id="sched-name"
                                    className="mt-1.5"
                                    value={formName}
                                    onChange={(e) =>
                                        setFormName(e.target.value)
                                    }
                                    placeholder={describeSchedule(
                                        freqType,
                                        minuteInterval,
                                        hourInterval,
                                        day,
                                        hour,
                                        minute,
                                    )}
                                />
                            </div>
                        </div>

                        <DialogFooter className="gap-2">
                            <DialogClose asChild>
                                <Button variant="secondary">Cancel</Button>
                            </DialogClose>
                            <Button
                                onClick={handleSubmit}
                                disabled={submitting || !formMessage.trim()}
                            >
                                {submitting ? (
                                    <Loader2 className="size-4 animate-spin" />
                                ) : editingId ? (
                                    'Save'
                                ) : (
                                    'Create'
                                )}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </div>

            {loading ? (
                <div className="flex items-center justify-center py-6">
                    <Loader2 className="size-4 animate-spin text-muted-foreground" />
                </div>
            ) : crons.length === 0 ? (
                <p className="py-4 text-center text-sm text-muted-foreground">
                    No schedules configured.
                </p>
            ) : (
                <div className="divide-y rounded-lg border">
                    {crons.map((cron) => {
                        const isSystem = cron.description === 'system';
                        return (
                            <div
                                key={cron.id}
                                className="flex items-center justify-between gap-3 px-4 py-3"
                            >
                                <div className="min-w-0 flex-1">
                                    <div className="flex items-center gap-2">
                                        <p
                                            className={cn(
                                                'text-sm font-medium',
                                                !cron.enabled &&
                                                    'text-muted-foreground line-through',
                                            )}
                                        >
                                            {cron.name}
                                        </p>
                                        <Badge
                                            variant="secondary"
                                            className="shrink-0 text-xs"
                                        >
                                            {formatIntervalHuman(
                                                cron.schedule.everyMs,
                                            )}
                                        </Badge>
                                        {isSystem && (
                                            <Badge
                                                variant="outline"
                                                className="shrink-0 gap-1 text-xs"
                                            >
                                                <Lock className="size-2.5" />
                                                System
                                            </Badge>
                                        )}
                                        {!cron.enabled && (
                                            <Badge
                                                variant="outline"
                                                className="shrink-0 text-xs"
                                            >
                                                Paused
                                            </Badge>
                                        )}
                                    </div>
                                    <p className="mt-0.5 truncate text-xs text-muted-foreground">
                                        {cron.payload.message}
                                    </p>
                                </div>
                                <div className="flex shrink-0 items-center gap-1">
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        className="size-8 p-0"
                                        title={
                                            cron.enabled ? 'Pause' : 'Resume'
                                        }
                                        onClick={() =>
                                            handleToggle(cron.id, !cron.enabled)
                                        }
                                    >
                                        {cron.enabled ? (
                                            <Pause className="size-3.5" />
                                        ) : (
                                            <Play className="size-3.5" />
                                        )}
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        className="size-8 p-0"
                                        title="Run now"
                                        onClick={() => handleRun(cron.id)}
                                    >
                                        <RefreshCw className="size-3.5" />
                                    </Button>
                                    {!isSystem && (
                                        <DropdownMenu>
                                            <DropdownMenuTrigger asChild>
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    className="size-8 p-0"
                                                >
                                                    <MoreHorizontal className="size-3.5" />
                                                </Button>
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent align="end">
                                                <DropdownMenuItem
                                                    onClick={() =>
                                                        openEdit(cron)
                                                    }
                                                >
                                                    Edit
                                                </DropdownMenuItem>
                                                <DropdownMenuSeparator />
                                                <DropdownMenuItem
                                                    className="text-destructive"
                                                    onClick={() =>
                                                        handleDelete(cron.id)
                                                    }
                                                >
                                                    Delete
                                                </DropdownMenuItem>
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    )}
                                </div>
                            </div>
                        );
                    })}
                </div>
            )}
        </div>
    );
}

// ─── Workspace Tab ──────────────────────────────────────────────

type WorkspaceFile = {
    name: string;
    path: string;
    type: 'file' | 'directory';
    size: number;
    modified_at: string;
};

function formatBytes(bytes: number): string {
    if (bytes === 0) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(1024));
    return `${(bytes / Math.pow(1024, i)).toFixed(i > 0 ? 1 : 0)} ${units[i]}`;
}

function WorkspaceTab({ agent }: { agent: Agent }) {
    const [files, setFiles] = useState<WorkspaceFile[]>([]);
    const [currentPath, setCurrentPath] = useState('');
    const [loading, setLoading] = useState(false);
    const [uploading, setUploading] = useState(false);
    const [usage, setUsage] = useState(0);
    const [limit, setLimit] = useState(52_428_800);
    const [showNewFolder, setShowNewFolder] = useState(false);
    const [newFolderName, setNewFolderName] = useState('');
    const [dragActive, setDragActive] = useState(false);
    const fileInputRef = useRef<HTMLInputElement>(null);

    const isDeployable = !!(agent.server_id && agent.status !== 'deploying');

    const fetchFiles = useCallback(
        async (options?: { showLoader?: boolean; fresh?: boolean }) => {
            const { showLoader = true, fresh = false } = options ?? {};
            if (showLoader) setLoading(true);
            try {
                const url = `/agents/${agent.id}/workspace${fresh ? '?fresh=1' : ''}`;
                const res = await fetch(url, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });
                const data = await res.json();
                setFiles(data.files ?? []);
                setUsage(data.usage ?? 0);
                setLimit(data.limit ?? 52_428_800);
            } finally {
                if (showLoader) setLoading(false);
            }
        },
        [agent.id],
    );

    useEffect(() => {
        if (isDeployable) {
            fetchFiles({ showLoader: true });
            setCurrentPath('');
        }
    }, [isDeployable, fetchFiles]);

    // Real-time workspace updates via Reverb
    useEcho<{ agent_id: string }>(
        `team.${agent.team_id}`,
        '.workspace.updated',
        (data) => {
            if (data.agent_id === agent.id) {
                fetchFiles({ showLoader: false, fresh: true });
            }
        },
    );

    if (!isDeployable) {
        return (
            <div className="rounded-lg border border-dashed p-8 text-center">
                <FileText className="mx-auto size-8 text-muted-foreground/50" />
                <p className="mt-3 text-sm text-muted-foreground">
                    Deploy this agent to access its workspace.
                </p>
            </div>
        );
    }

    // Files in current directory
    const currentFiles = files.filter((f) => {
        if (!currentPath) {
            return !f.path.includes('/');
        }
        const prefix = currentPath + '/';
        if (!f.path.startsWith(prefix)) return false;
        const rest = f.path.slice(prefix.length);
        return !rest.includes('/');
    });

    // Sort: directories first, then alphabetically
    const sortedFiles = [...currentFiles].sort((a, b) => {
        if (a.type !== b.type) return a.type === 'directory' ? -1 : 1;
        return a.name.localeCompare(b.name);
    });

    const breadcrumbSegments = currentPath ? currentPath.split('/') : [];

    async function handleUpload(fileList: FileList | null) {
        if (!fileList || fileList.length === 0) return;
        setUploading(true);
        try {
            const formData = new FormData();
            for (let i = 0; i < fileList.length; i++) {
                formData.append('files[]', fileList[i]);
            }
            if (currentPath) formData.append('path', currentPath);

            const csrfToken = decodeURIComponent(
                document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '',
            );

            await fetch(`/agents/${agent.id}/workspace/upload`, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': csrfToken,
                },
                credentials: 'same-origin',
                body: formData,
            });

            await fetchFiles({ showLoader: false, fresh: true });
        } finally {
            setUploading(false);
            if (fileInputRef.current) fileInputRef.current.value = '';
        }
    }

    async function handleCreateFolder() {
        if (!newFolderName.trim()) return;
        const csrfToken = decodeURIComponent(
            document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '',
        );
        await fetch(`/agents/${agent.id}/workspace/folder`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': csrfToken,
            },
            credentials: 'same-origin',
            body: JSON.stringify({ name: newFolderName, path: currentPath }),
        });
        setNewFolderName('');
        setShowNewFolder(false);
        await fetchFiles({ showLoader: false, fresh: true });
    }

    async function handleDelete(path: string) {
        if (!confirm('Are you sure you want to delete this?')) return;
        const csrfToken = decodeURIComponent(
            document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '',
        );
        await fetch(`/agents/${agent.id}/workspace`, {
            method: 'DELETE',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': csrfToken,
            },
            credentials: 'same-origin',
            body: JSON.stringify({ path }),
        });
        await fetchFiles({ showLoader: false, fresh: true });
    }

    function handleDownload(path: string) {
        window.open(
            `/agents/${agent.id}/workspace/download?path=${encodeURIComponent(path)}`,
            '_blank',
        );
    }

    function handleDragOver(e: React.DragEvent) {
        e.preventDefault();
        setDragActive(true);
    }

    function handleDragLeave(e: React.DragEvent) {
        e.preventDefault();
        setDragActive(false);
    }

    function handleDrop(e: React.DragEvent) {
        e.preventDefault();
        setDragActive(false);
        handleUpload(e.dataTransfer.files);
    }

    const usagePercent = limit > 0 ? Math.min((usage / limit) * 100, 100) : 0;

    return (
        <div className="space-y-4">
            {/* Storage bar */}
            <div className="rounded-lg border px-4 py-3">
                <div className="flex items-center justify-between text-sm">
                    <span className="text-muted-foreground">
                        {formatBytes(usage)} / {formatBytes(limit)} used
                    </span>
                    <span className="text-muted-foreground">
                        {Math.round(usagePercent)}%
                    </span>
                </div>
                <div className="mt-1.5 h-1.5 w-full overflow-hidden rounded-full bg-muted">
                    <div
                        className={cn(
                            'h-full rounded-full transition-all',
                            usagePercent > 90 ? 'bg-destructive' : 'bg-primary',
                        )}
                        style={{ width: `${usagePercent}%` }}
                    />
                </div>
            </div>

            {/* Breadcrumbs + actions */}
            <div className="flex items-center justify-between">
                <div className="flex items-center gap-1 text-sm">
                    <button
                        type="button"
                        onClick={() => setCurrentPath('')}
                        className={cn(
                            'hover:text-foreground',
                            currentPath
                                ? 'text-muted-foreground'
                                : 'font-medium text-foreground',
                        )}
                    >
                        workspace
                    </button>
                    {breadcrumbSegments.map((segment, i) => {
                        const path = breadcrumbSegments
                            .slice(0, i + 1)
                            .join('/');
                        const isLast = i === breadcrumbSegments.length - 1;
                        return (
                            <span
                                key={path}
                                className="flex items-center gap-1"
                            >
                                <ChevronRight className="size-3 text-muted-foreground" />
                                <button
                                    type="button"
                                    onClick={() => setCurrentPath(path)}
                                    className={cn(
                                        'hover:text-foreground',
                                        isLast
                                            ? 'font-medium text-foreground'
                                            : 'text-muted-foreground',
                                    )}
                                >
                                    {segment}
                                </button>
                            </span>
                        );
                    })}
                </div>
                <div className="flex items-center gap-2">
                    <Button
                        variant="ghost"
                        size="icon"
                        className="size-7"
                        onClick={() => fetchFiles({ fresh: true })}
                        disabled={loading}
                    >
                        <RefreshCw
                            className={cn(
                                'size-3.5',
                                loading && 'animate-spin',
                            )}
                        />
                        <span className="sr-only">Refresh</span>
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => setShowNewFolder(!showNewFolder)}
                        className="gap-1.5"
                    >
                        <FolderPlus className="size-3.5" />
                        Folder
                    </Button>
                    <Button
                        size="sm"
                        onClick={() => fileInputRef.current?.click()}
                        disabled={uploading}
                        className="gap-1.5"
                    >
                        {uploading ? (
                            <Loader2 className="size-3.5 animate-spin" />
                        ) : (
                            <Upload className="size-3.5" />
                        )}
                        Upload
                    </Button>
                    <input
                        ref={fileInputRef}
                        type="file"
                        multiple
                        className="hidden"
                        onChange={(e) => handleUpload(e.target.files)}
                    />
                </div>
            </div>

            {/* New folder inline form */}
            {showNewFolder && (
                <div className="flex items-center gap-2">
                    <Input
                        value={newFolderName}
                        onChange={(e) => setNewFolderName(e.target.value)}
                        placeholder="Folder name"
                        className="max-w-xs"
                        onKeyDown={(e) =>
                            e.key === 'Enter' && handleCreateFolder()
                        }
                        autoFocus
                    />
                    <Button
                        size="sm"
                        onClick={handleCreateFolder}
                        disabled={!newFolderName.trim()}
                    >
                        Create
                    </Button>
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => {
                            setShowNewFolder(false);
                            setNewFolderName('');
                        }}
                    >
                        Cancel
                    </Button>
                </div>
            )}

            {/* File list */}
            <div
                className={cn(
                    'rounded-lg border transition-colors',
                    dragActive && 'border-primary bg-primary/5',
                )}
                onDragOver={handleDragOver}
                onDragLeave={handleDragLeave}
                onDrop={handleDrop}
            >
                {loading ? (
                    <div className="flex items-center justify-center py-12">
                        <Loader2 className="size-5 animate-spin text-muted-foreground" />
                    </div>
                ) : sortedFiles.length === 0 && !currentPath ? (
                    <div className="py-12 text-center">
                        <FolderOpen className="mx-auto size-8 text-muted-foreground/50" />
                        <p className="mt-3 text-sm text-muted-foreground">
                            Upload documents for your agent to reference.
                        </p>
                        <Button
                            variant="outline"
                            size="sm"
                            className="mt-3 gap-1.5"
                            onClick={() => fileInputRef.current?.click()}
                        >
                            <Upload className="size-3.5" />
                            Upload Files
                        </Button>
                    </div>
                ) : (
                    <div className="divide-y">
                        {currentPath && (
                            <button
                                type="button"
                                className="flex w-full items-center gap-3 px-4 py-2.5 text-left text-sm hover:bg-muted/50"
                                onClick={() => {
                                    const parent = currentPath
                                        .split('/')
                                        .slice(0, -1)
                                        .join('/');
                                    setCurrentPath(parent);
                                }}
                            >
                                <FolderOpen className="size-4 text-muted-foreground" />
                                <span className="text-muted-foreground">
                                    ..
                                </span>
                            </button>
                        )}
                        {sortedFiles.map((file) => (
                            <div
                                key={file.path}
                                className="group flex items-center gap-3 px-4 py-2.5 text-sm"
                            >
                                {file.type === 'directory' ? (
                                    <button
                                        type="button"
                                        className="flex flex-1 items-center gap-3 text-left hover:text-foreground"
                                        onClick={() =>
                                            setCurrentPath(file.path)
                                        }
                                    >
                                        <FolderOpen className="size-4 text-amber-500" />
                                        <span className="flex-1 font-medium">
                                            {file.name}
                                        </span>
                                    </button>
                                ) : (
                                    <div className="flex flex-1 items-center gap-3">
                                        <FileText className="size-4 text-muted-foreground" />
                                        <span className="flex-1">
                                            {file.name}
                                        </span>
                                        <span className="text-xs text-muted-foreground">
                                            {formatBytes(file.size)}
                                        </span>
                                    </div>
                                )}
                                <span className="text-xs text-muted-foreground">
                                    {relativeTime(file.modified_at)}
                                </span>
                                <DropdownMenu>
                                    <DropdownMenuTrigger asChild>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            className="size-7 p-0 opacity-0 group-hover:opacity-100"
                                        >
                                            <MoreHorizontal className="size-4" />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end">
                                        {file.type === 'file' && (
                                            <DropdownMenuItem
                                                onClick={() =>
                                                    handleDownload(file.path)
                                                }
                                            >
                                                <Download className="mr-2 size-4" />
                                                Download
                                            </DropdownMenuItem>
                                        )}
                                        <DropdownMenuItem
                                            className="text-destructive focus:text-destructive"
                                            onClick={() =>
                                                handleDelete(file.path)
                                            }
                                        >
                                            <Trash2 className="mr-2 size-4" />
                                            Delete
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </div>
                        ))}
                        {sortedFiles.length === 0 && currentPath && (
                            <div className="py-8 text-center text-sm text-muted-foreground">
                                This folder is empty.
                            </div>
                        )}
                    </div>
                )}

                {/* Drop zone overlay when dragging */}
                {dragActive && (
                    <div className="border-t border-dashed border-primary py-4 text-center text-sm text-primary">
                        Drop files here to upload
                    </div>
                )}
            </div>
        </div>
    );
}

// ─── Environment Tab ─────────────────────────────────────────────

function EnvironmentTab({ agent }: { agent: Agent }) {
    const [revealed, setRevealed] = useState(false);
    const [content, setContent] = useState('');
    const [originalContent, setOriginalContent] = useState('');
    const [loading, setLoading] = useState(false);
    const [saving, setSaving] = useState(false);
    const [saved, setSaved] = useState(false);
    const dirty = content !== originalContent;

    if (!agent.server_id || agent.status === 'deploying') {
        return (
            <div className="rounded-lg border border-dashed p-8 text-center">
                <Key className="mx-auto size-8 text-muted-foreground/50" />
                <p className="mt-3 text-sm text-muted-foreground">
                    Deploy this agent to manage its environment variables.
                </p>
            </div>
        );
    }

    async function handleReveal() {
        setLoading(true);
        try {
            const res = await fetch(`/agents/${agent.id}/env`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            const data = await res.json();
            setContent(data.content ?? '');
            setOriginalContent(data.content ?? '');
            setRevealed(true);
        } finally {
            setLoading(false);
        }
    }

    async function handleSave() {
        setSaving(true);
        try {
            await fetch(`/agents/${agent.id}/env`, {
                method: 'PUT',
                headers: csrfHeader(),
                credentials: 'same-origin',
                body: JSON.stringify({ content }),
            });
            setOriginalContent(content);
            setSaved(true);
            setTimeout(() => setSaved(false), 2000);
        } finally {
            setSaving(false);
        }
    }

    function handleHide() {
        setRevealed(false);
        setContent('');
        setOriginalContent('');
    }

    if (!revealed) {
        return (
            <div className="relative overflow-hidden rounded-lg border border-white/10 bg-zinc-900/80 p-6">
                {/* Blurred placeholder lines */}
                <div
                    className="space-y-2 blur-sm select-none"
                    aria-hidden="true"
                >
                    <div className="font-mono text-sm text-zinc-500">
                        MAILBOXKIT_API_KEY=••••••••••••••••
                    </div>
                    <div className="font-mono text-sm text-zinc-500">
                        CUSTOM_SECRET=••••••••••••••••••••
                    </div>
                    <div className="font-mono text-sm text-zinc-500">
                        API_ENDPOINT=••••••••••••••••••••
                    </div>
                    <div className="font-mono text-sm text-zinc-500">
                        DATABASE_URL=••••••••••••••••••••
                    </div>
                    <div className="font-mono text-sm text-zinc-500">
                        WEBHOOK_SECRET=••••••••••••••••••
                    </div>
                </div>

                {/* Reveal overlay */}
                <div className="absolute inset-0 flex flex-col items-center justify-center bg-zinc-900/60 backdrop-blur-[2px]">
                    <Lock className="mb-3 size-5 text-zinc-400" />
                    <p className="mb-4 text-sm text-zinc-400">
                        Environment variables should not be shared publicly.
                    </p>
                    <Button
                        onClick={handleReveal}
                        disabled={loading}
                        variant="outline"
                        className="gap-2 border-white/10 bg-zinc-800 text-zinc-100 hover:bg-zinc-700"
                    >
                        {loading ? (
                            <Loader2 className="size-4 animate-spin" />
                        ) : (
                            <Eye className="size-4" />
                        )}
                        Reveal Environment
                    </Button>
                </div>
            </div>
        );
    }

    return (
        <div className="space-y-3">
            <textarea
                value={content}
                onChange={(e) => setContent(e.target.value)}
                className="min-h-[400px] w-full resize-y rounded-lg border border-white/10 bg-zinc-900 p-4 font-mono text-sm text-zinc-100 focus:ring-2 focus:ring-primary focus:outline-none"
                spellCheck={false}
                placeholder="# Add environment variables, one per line&#10;KEY=value"
            />
            <div className="flex items-center justify-between">
                <button
                    type="button"
                    onClick={handleHide}
                    className="flex items-center gap-1.5 text-xs text-muted-foreground hover:text-foreground"
                >
                    <EyeOff className="size-3.5" />
                    Hide
                </button>
                <div className="flex items-center gap-2">
                    {saved && (
                        <span className="flex items-center gap-1 text-xs text-green-500">
                            <Check className="size-3.5" />
                            Saved
                        </span>
                    )}
                    <Button
                        onClick={handleSave}
                        disabled={!dirty || saving}
                        size="sm"
                    >
                        {saving ? (
                            <Loader2 className="mr-1.5 size-3.5 animate-spin" />
                        ) : null}
                        Save
                    </Button>
                </div>
            </div>
        </div>
    );
}

function BrowserTab({ browserUrl }: { browserUrl?: string | null }) {
    if (!browserUrl) {
        return (
            <div className="flex flex-col items-center justify-center py-16 text-center">
                <Monitor className="size-10 text-muted-foreground/50" />
                <h3 className="mt-3 text-sm font-medium">
                    Browser not available
                </h3>
                <p className="mt-1 text-xs text-muted-foreground">
                    Browser viewing will be available once the server is fully
                    provisioned.
                </p>
            </div>
        );
    }

    return (
        <div
            className="flex flex-col gap-3"
            style={{ minHeight: 'calc(100vh - 220px)' }}
        >
            <div className="flex items-center justify-between">
                <div>
                    <h3 className="text-sm font-medium">Live Browser</h3>
                    <p className="text-xs text-muted-foreground">
                        View and control the agent's browser. Useful for solving
                        CAPTCHAs or completing verification steps.
                    </p>
                </div>
                <a href={browserUrl} target="_blank" rel="noopener noreferrer">
                    <Button variant="outline" size="sm">
                        <ExternalLink className="mr-1.5 size-3.5" />
                        Open in new tab
                    </Button>
                </a>
            </div>
            <div className="flex-1 overflow-hidden rounded-lg border bg-black">
                <iframe
                    src={browserUrl}
                    className="h-full w-full"
                    style={{ minHeight: 'calc(100vh - 300px)' }}
                    allow="clipboard-read; clipboard-write"
                />
            </div>
        </div>
    );
}

type SettingsSection = 'configuration' | 'environment' | 'logs' | 'danger';

function SettingsTab({ agent }: { agent: Agent }) {
    const [section, setSection] = useState<SettingsSection>('configuration');

    const sections: { id: SettingsSection; label: string; show?: boolean }[] = [
        { id: 'configuration', label: 'Configuration' },
        { id: 'environment', label: 'Environment' },
        {
            id: 'logs',
            label: 'Debug Logs',
            show: agent.status === 'active' && !!agent.server,
        },
        { id: 'danger', label: 'Danger Zone' },
    ];

    const visibleSections = sections.filter((s) => s.show !== false);

    return (
        <div className="flex flex-col gap-6 md:flex-row md:gap-12">
            <aside className="w-full shrink-0 md:w-44">
                <nav
                    className="flex flex-row gap-1 overflow-x-auto md:flex-col"
                    aria-label="Agent settings"
                >
                    {visibleSections.map((s) => (
                        <button
                            key={s.id}
                            type="button"
                            onClick={() => setSection(s.id)}
                            className={cn(
                                'rounded-lg px-3 py-1.5 text-left text-sm font-medium whitespace-nowrap transition-colors',
                                section === s.id
                                    ? 'bg-muted text-foreground'
                                    : 'text-muted-foreground hover:text-foreground',
                            )}
                        >
                            {s.label}
                        </button>
                    ))}
                </nav>
            </aside>

            <div className="min-w-0 flex-1">
                {section === 'configuration' && (
                    <div className="space-y-4">
                        <div>
                            <h3 className="text-sm font-medium">
                                Configuration
                            </h3>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Configure prompts, identity, and tools for this
                                agent.
                            </p>
                        </div>
                        <Button variant="outline" size="sm" asChild>
                            <Link
                                href={`/agents/${agent.id}/configure`}
                                className="gap-1.5"
                            >
                                <Settings className="size-3.5" />
                                Open Configuration
                            </Link>
                        </Button>
                    </div>
                )}

                {section === 'environment' && <EnvironmentTab agent={agent} />}

                {section === 'logs' &&
                    agent.status === 'active' &&
                    agent.server && (
                        <div className="space-y-4">
                            <div>
                                <h3 className="text-sm font-medium">
                                    Debug Logs
                                </h3>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    View recent gateway and agent logs from the
                                    server.
                                </p>
                            </div>
                            <DebugLogsDialog agentId={agent.id} />
                        </div>
                    )}

                {section === 'danger' && (
                    <div className="space-y-4">
                        <div>
                            <h3 className="text-sm font-medium">Danger Zone</h3>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Irreversible actions for this agent.
                            </p>
                        </div>
                        <div className="rounded-lg border border-destructive/30 px-4 py-3">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm font-medium">
                                        Delete agent
                                    </p>
                                    <p className="text-sm text-muted-foreground">
                                        Permanently remove this agent and its
                                        configuration.
                                    </p>
                                </div>
                                <DeleteConfirmDialog
                                    name={agent.name}
                                    label="agent"
                                    onConfirm={() =>
                                        router.delete(`/agents/${agent.id}`)
                                    }
                                    trigger={
                                        <Button variant="destructive" size="sm">
                                            Delete
                                        </Button>
                                    }
                                />
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
}

// ─── Page ────────────────────────────────────────────────────────

export default function ShowAgent({
    agent,
    activities = [],
    teamId = '',
    browserUrl = null,
}: {
    agent: Agent;
    activities?: AgentActivity[];
    teamId?: string;
    browserUrl?: string | null;
}) {
    // Real-time agent updates via Reverb (replaces polling)
    useEcho<{ agent_id: string }>(
        `team.${teamId}`,
        '.agent.updated',
        (data) => {
            if (data.agent_id === agent.id) {
                router.reload({ only: ['agent'] });
            }
        },
    );

    const validTabs: Tab[] = [
        'overview',
        'email',
        'channels',
        'schedules',
        'workspace',
        'memory',
        'browser',
        'settings',
    ];
    const initialTab = (() => {
        const hash = window.location.hash.slice(1) as Tab;
        return validTabs.includes(hash) ? hash : 'overview';
    })();
    const [activeTab, setActiveTab] = useState<Tab>(initialTab);
    const [debugLogsOpen, setDebugLogsOpen] = useState(false);

    function switchTab(tab: Tab) {
        setActiveTab(tab);
        window.history.replaceState(null, '', `#${tab}`);
    }

    const isHermes = agent.harness_type === 'hermes';

    const tabs: { id: Tab; label: string }[] = [
        { id: 'overview', label: 'Overview' },
        { id: 'email', label: 'Email Inbox' },
        ...(!isHermes ? [{ id: 'browser' as Tab, label: 'Browser' }] : []),
        { id: 'workspace', label: 'Workspace' },
        { id: 'memory', label: 'Memory' },
        { id: 'schedules', label: 'Scheduled Tasks' },
        ...(agent.agent_mode !== 'workforce' ? [{ id: 'channels' as Tab, label: 'Channels' }] : []),
        { id: 'settings', label: 'Settings' },
    ];

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Agents', href: '/agents' },
        { title: agent.name, href: `/agents/${agent.id}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={agent.name} />

            <div className="flex min-h-0 flex-1 flex-col">
                {/* Header + Tabs */}
                <div className="shrink-0">
                    <div className="border-b px-4 sm:px-6">
                        <div className="flex items-center justify-between gap-4 py-4">
                            <div className="flex items-center gap-3.5">
                                <AgentAvatar
                                    agent={agent}
                                    className="size-10 text-sm"
                                />
                                <div>
                                    <div className="flex items-center gap-2">
                                        <h1 className="text-base font-semibold">
                                            {agent.name}
                                        </h1>
                                        <StatusBadge status={agent.status} />
                                        {agent.harness_type && (
                                            <span className="rounded bg-muted px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground">
                                                {agent.harness_type === 'hermes'
                                                    ? 'Hermes'
                                                    : 'OpenClaw'}
                                            </span>
                                        )}
                                    </div>
                                    <p className="mt-0.5 text-xs text-muted-foreground">
                                        {roleLabels[agent.role] ?? agent.role}
                                        {agent.model_primary &&
                                            ` \u00b7 ${agent.model_primary}`}
                                    </p>
                                </div>
                            </div>

                            <div className="flex items-center gap-2">
                                {agent.agent_mode !== 'workforce' && (
                                    <Link
                                        href={`/agents/${agent.id}/chat`}
                                        className="inline-flex items-center gap-1.5 rounded-md bg-primary px-3 py-1.5 text-xs font-medium text-primary-foreground transition-colors hover:bg-primary/90"
                                    >
                                        <MessageSquare className="size-3.5" />
                                        Chat
                                    </Link>
                                )}
                                <DropdownMenu>
                                    <DropdownMenuTrigger asChild>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            className="size-7"
                                        >
                                            <MoreHorizontal className="size-4" />
                                            <span className="sr-only">
                                                More actions
                                            </span>
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end">
                                        <DropdownMenuItem asChild>
                                            <Link
                                                href={`/agents/${agent.id}/configure`}
                                            >
                                                <Settings className="mr-2 size-4" />
                                                Configure
                                            </Link>
                                        </DropdownMenuItem>
                                        {agent.status === 'active' &&
                                            agent.server && (
                                                <>
                                                    <DropdownMenuItem
                                                        onClick={() =>
                                                            setDebugLogsOpen(
                                                                true,
                                                            )
                                                        }
                                                    >
                                                        <ScrollText className="mr-2 size-4" />
                                                        View Logs
                                                    </DropdownMenuItem>
                                                    <DropdownMenuItem
                                                        onClick={() =>
                                                            router.post(
                                                                `/agents/${agent.id}/resync-channels`,
                                                                {},
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            )
                                                        }
                                                    >
                                                        <RefreshCw className="mr-2 size-4" />
                                                        Re-sync Channels
                                                    </DropdownMenuItem>
                                                </>
                                            )}
                                        <DropdownMenuSeparator />
                                        <DropdownMenuItem
                                            className="text-destructive focus:text-destructive"
                                            onClick={() =>
                                                switchTab('settings')
                                            }
                                        >
                                            <Trash2 className="mr-2 size-4" />
                                            Delete Agent
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </div>
                        </div>

                        {agent.is_syncing && (
                            <div className="mb-3 flex items-center gap-3 rounded-lg bg-blue-50 p-3 dark:bg-blue-950/30">
                                <Loader2 className="size-4 shrink-0 animate-spin text-blue-600 dark:text-blue-400" />
                                <p className="text-sm text-blue-700 dark:text-blue-300">
                                    Applying changes to server...
                                </p>
                            </div>
                        )}

                        <ErrorBanner agent={agent} />
                    </div>

                    {/* Tab bar */}
                    <div className="border-b px-4 sm:px-6">
                        <div className="-mb-px flex gap-1">
                            {tabs.map((tab) => (
                                <button
                                    key={tab.id}
                                    type="button"
                                    onClick={() => switchTab(tab.id)}
                                    className={cn(
                                        'relative px-3 py-2 text-sm font-medium transition-colors duration-150',
                                        activeTab === tab.id
                                            ? 'text-foreground'
                                            : 'text-muted-foreground hover:text-foreground',
                                    )}
                                >
                                    {tab.label}
                                    {activeTab === tab.id && (
                                        <span className="absolute inset-x-0 bottom-0 h-0.5 rounded-full bg-primary" />
                                    )}
                                </button>
                            ))}
                        </div>
                    </div>
                </div>
                {/* end header + tabs */}

                {/* Tab content — scrollable */}
                <div className="min-h-0 flex-1 overflow-y-auto">
                    {activeTab === 'email' ? (
                        agent.email_connection ? (
                            <EmailTab agent={agent} />
                        ) : (
                            <div className="flex flex-col items-center justify-center gap-3 px-4 py-20 text-center">
                                <div className="flex size-12 items-center justify-center rounded-xl border border-foreground/10 bg-background shadow-sm">
                                    <svg
                                        xmlns="http://www.w3.org/2000/svg"
                                        width="20"
                                        height="20"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        strokeWidth="2"
                                        strokeLinecap="round"
                                        strokeLinejoin="round"
                                        className="text-muted-foreground"
                                    >
                                        <rect
                                            width="20"
                                            height="16"
                                            x="2"
                                            y="4"
                                            rx="2"
                                        />
                                        <path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7" />
                                    </svg>
                                </div>
                                <h3 className="text-lg font-semibold">
                                    Email Identities
                                </h3>
                                <p className="max-w-sm text-sm text-muted-foreground">
                                    Email identities are available with
                                    Provision Cloud.{' '}
                                    <a
                                        href="https://provision.ai"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="text-primary underline underline-offset-4 hover:text-primary/80"
                                    >
                                        Learn more at provision.ai
                                    </a>
                                </p>
                            </div>
                        )
                    ) : activeTab === 'settings' ? (
                        <div className="px-4 py-6 sm:px-6">
                            <SettingsTab agent={agent} />
                        </div>
                    ) : (
                        <div className="px-4 py-6 sm:px-6">
                            {activeTab === 'browser' ? (
                                <BrowserTab browserUrl={browserUrl} />
                            ) : (
                                <div className="mx-auto max-w-3xl">
                                    {activeTab === 'overview' && (
                                        <>
                                            <OverviewTab agent={agent} />
                                            {activities.length > 0 && (
                                                <section className="mt-8">
                                                    <h3 className="text-sm font-medium">
                                                        Recent Activity
                                                    </h3>
                                                    <div className="mt-3">
                                                        <ActivityFeed
                                                            activities={
                                                                activities
                                                            }
                                                            teamId={teamId}
                                                            agentId={agent.id}
                                                        />
                                                    </div>
                                                </section>
                                            )}
                                        </>
                                    )}
                                    {activeTab === 'channels' && (
                                        <ChannelsTab agent={agent} />
                                    )}
                                    {activeTab === 'schedules' && (
                                        <SchedulesSection agent={agent} />
                                    )}
                                    {activeTab === 'workspace' && (
                                        <WorkspaceTab agent={agent} />
                                    )}
                                    {activeTab === 'memory' && (
                                        <MemoryBrowser agent={agent} />
                                    )}
                                </div>
                            )}
                        </div>
                    )}
                </div>

                <DebugLogsDialog
                    agentId={agent.id}
                    open={debugLogsOpen}
                    onOpenChange={setDebugLogsOpen}
                />
            </div>
        </AppLayout>
    );
}
