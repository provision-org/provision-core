import { ArrowLeft, Inbox, Loader2, Mail, RefreshCw } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { cn } from '@/lib/utils';

type MessageSummary = {
    id: string | number;
    ulid: string;
    from_email: string;
    subject: string;
    text_body: string | null;
    created_at: string;
};

type MessageDetail = {
    id: string | number;
    from_email: string;
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

type Meta = {
    current_page: number;
    last_page: number;
};

function formatDate(dateString: string): string {
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });
}

function formatFileSize(bytes: number): string {
    if (bytes >= 1_000_000) return `${(bytes / 1_000_000).toFixed(1)} MB`;
    if (bytes >= 1_000) return `${(bytes / 1_000).toFixed(1)} KB`;
    return `${bytes} B`;
}

export default function InboxDialog({ agentId }: { agentId: string | number }) {
    const [open, setOpen] = useState(false);
    const [messages, setMessages] = useState<MessageSummary[]>([]);
    const [meta, setMeta] = useState<Meta | null>(null);
    const [selectedMessage, setSelectedMessage] =
        useState<MessageDetail | null>(null);
    const [loading, setLoading] = useState(false);
    const [messageLoading, setMessageLoading] = useState(false);
    const [error, setError] = useState('');
    const [page, setPage] = useState(1);

    const fetchMessages = useCallback(
        async (p: number = 1) => {
            setLoading(true);
            setError('');
            try {
                const res = await fetch(`/agents/${agentId}/inbox?page=${p}`, {
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
        [agentId],
    );

    const fetchMessage = useCallback(
        async (messageId: string | number) => {
            setMessageLoading(true);
            setError('');
            try {
                const res = await fetch(
                    `/agents/${agentId}/inbox/${messageId}`,
                    {
                        headers: { Accept: 'application/json' },
                    },
                );
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
        [agentId],
    );

    useEffect(() => {
        if (open) {
            setSelectedMessage(null);
            fetchMessages(1);
        }
    }, [open, fetchMessages]);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button
                    variant="ghost"
                    size="sm"
                    className="h-7 gap-1.5 px-2 text-xs"
                >
                    <Inbox className="size-3" />
                    Inbox
                </Button>
            </DialogTrigger>
            <DialogContent className="max-w-2xl">
                {selectedMessage ? (
                    <MessageView
                        message={selectedMessage}
                        onBack={() => setSelectedMessage(null)}
                    />
                ) : (
                    <>
                        <div className="flex items-center justify-between">
                            <DialogTitle>Email Inbox</DialogTitle>
                            <Button
                                variant="ghost"
                                size="sm"
                                className="gap-1.5"
                                disabled={loading}
                                onClick={() => fetchMessages(page)}
                            >
                                <RefreshCw
                                    className={cn(
                                        'size-3.5',
                                        loading && 'animate-spin',
                                    )}
                                />
                                Refresh
                            </Button>
                        </div>
                        <DialogDescription>
                            Recent emails received by this agent.
                        </DialogDescription>

                        {loading && messages.length === 0 && !error ? (
                            <div className="flex items-center justify-center py-12">
                                <Loader2 className="size-6 animate-spin text-muted-foreground" />
                            </div>
                        ) : error ? (
                            <div className="rounded-lg border border-destructive/50 bg-destructive/10 p-4">
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
                        ) : messages.length === 0 ? (
                            <div className="flex flex-col items-center justify-center py-12 text-center">
                                <Mail className="mb-3 size-8 text-muted-foreground" />
                                <p className="text-sm font-medium">
                                    No emails yet
                                </p>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    Emails sent to this agent will appear here.
                                </p>
                            </div>
                        ) : (
                            <>
                                <div className="max-h-96 divide-y overflow-y-auto rounded-lg border">
                                    {messages.map((msg) => (
                                        <button
                                            key={msg.id}
                                            className="flex w-full flex-col gap-0.5 px-4 py-3 text-left transition-colors hover:bg-muted/50"
                                            onClick={() =>
                                                fetchMessage(msg.ulid)
                                            }
                                        >
                                            <div className="flex items-center justify-between gap-2">
                                                <p className="truncate text-sm font-medium">
                                                    {msg.from_email}
                                                </p>
                                                <span className="shrink-0 text-xs text-muted-foreground">
                                                    {formatDate(msg.created_at)}
                                                </span>
                                            </div>
                                            <p className="truncate text-sm">
                                                {msg.subject || '(no subject)'}
                                            </p>
                                            {msg.text_body && (
                                                <p className="truncate text-xs text-muted-foreground">
                                                    {msg.text_body.slice(
                                                        0,
                                                        120,
                                                    )}
                                                </p>
                                            )}
                                        </button>
                                    ))}
                                </div>

                                {meta && meta.last_page > 1 && (
                                    <div className="flex items-center justify-between pt-2">
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            disabled={page <= 1 || loading}
                                            onClick={() =>
                                                fetchMessages(page - 1)
                                            }
                                        >
                                            Previous
                                        </Button>
                                        <span className="text-xs text-muted-foreground">
                                            Page {meta.current_page} of{' '}
                                            {meta.last_page}
                                        </span>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            disabled={
                                                page >= meta.last_page ||
                                                loading
                                            }
                                            onClick={() =>
                                                fetchMessages(page + 1)
                                            }
                                        >
                                            Next
                                        </Button>
                                    </div>
                                )}
                            </>
                        )}

                        {messageLoading && (
                            <div className="absolute inset-0 flex items-center justify-center rounded-lg bg-background/80">
                                <Loader2 className="size-6 animate-spin text-muted-foreground" />
                            </div>
                        )}
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}

function MessageView({
    message,
    onBack,
}: {
    message: MessageDetail;
    onBack: () => void;
}) {
    return (
        <>
            <div className="flex items-center gap-2">
                <Button
                    variant="ghost"
                    size="sm"
                    className="gap-1.5"
                    onClick={onBack}
                >
                    <ArrowLeft className="size-3.5" />
                    Back
                </Button>
            </div>
            <DialogTitle className="text-base">
                {message.subject || '(no subject)'}
            </DialogTitle>
            <DialogDescription className="sr-only">
                Email message details
            </DialogDescription>

            <dl className="space-y-1 text-sm">
                <div className="flex gap-2">
                    <dt className="shrink-0 text-muted-foreground">From:</dt>
                    <dd>{message.from_email}</dd>
                </div>
                {message.to_emails?.length > 0 && (
                    <div className="flex gap-2">
                        <dt className="shrink-0 text-muted-foreground">To:</dt>
                        <dd>{message.to_emails.join(', ')}</dd>
                    </div>
                )}
                {message.cc_emails?.length > 0 && (
                    <div className="flex gap-2">
                        <dt className="shrink-0 text-muted-foreground">CC:</dt>
                        <dd>{message.cc_emails.join(', ')}</dd>
                    </div>
                )}
                <div className="flex gap-2">
                    <dt className="shrink-0 text-muted-foreground">Date:</dt>
                    <dd>{formatDate(message.created_at)}</dd>
                </div>
            </dl>

            <div className="max-h-72 overflow-y-auto rounded-lg border bg-muted/30 p-4">
                <pre className="font-sans text-sm leading-relaxed whitespace-pre-wrap">
                    {message.text_body || '(no content)'}
                </pre>
            </div>

            {message.attachments?.length > 0 && (
                <div className="space-y-2">
                    <p className="text-xs font-medium text-muted-foreground">
                        Attachments ({message.attachments.length})
                    </p>
                    <div className="space-y-1">
                        {message.attachments.map((att) => (
                            <a
                                key={att.id}
                                href={att.url}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="flex items-center justify-between rounded-md border px-3 py-2 text-sm transition-colors hover:bg-muted/50"
                            >
                                <span className="truncate">{att.filename}</span>
                                <span className="shrink-0 text-xs text-muted-foreground">
                                    {formatFileSize(att.size)}
                                </span>
                            </a>
                        ))}
                    </div>
                </div>
            )}
        </>
    );
}
