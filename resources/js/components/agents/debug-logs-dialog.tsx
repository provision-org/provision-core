import { Loader2, RefreshCw, ScrollText } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { cn } from '@/lib/utils';

export default function DebugLogsDialog({
    agentId,
    open: controlledOpen,
    onOpenChange: controlledOnOpenChange,
}: {
    agentId: string | number;
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
}) {
    const [internalOpen, setInternalOpen] = useState(false);
    const isControlled = controlledOpen !== undefined;
    const open = isControlled ? controlledOpen : internalOpen;
    const onOpenChange = isControlled
        ? (controlledOnOpenChange ?? (() => {}))
        : setInternalOpen;

    const [logs, setLogs] = useState('');
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    const preRef = useRef<HTMLPreElement>(null);

    const fetchLogs = useCallback(async () => {
        setLoading(true);
        setError('');
        try {
            const res = await fetch(`/agents/${agentId}/logs`, {
                headers: { Accept: 'application/json' },
            });
            const data = await res.json();
            if (!res.ok) {
                setError(data.error || 'Failed to fetch logs.');
                setLogs('');
            } else {
                setLogs(data.logs || 'No logs available.');
            }
        } catch {
            setError('Failed to connect to server.');
            setLogs('');
        } finally {
            setLoading(false);
        }
    }, [agentId]);

    useEffect(() => {
        if (open) {
            fetchLogs();
        }
    }, [open, fetchLogs]);

    useEffect(() => {
        if (logs && preRef.current) {
            preRef.current.scrollTop = preRef.current.scrollHeight;
        }
    }, [logs]);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            {!isControlled && (
                <DialogTrigger asChild>
                    <Button variant="outline" size="sm" className="gap-1.5">
                        <ScrollText className="size-3.5" />
                        View Logs
                    </Button>
                </DialogTrigger>
            )}
            <DialogContent className="max-w-2xl">
                <div className="flex items-center justify-between">
                    <DialogTitle>Debug Logs</DialogTitle>
                    <Button
                        variant="ghost"
                        size="sm"
                        className="gap-1.5"
                        disabled={loading}
                        onClick={fetchLogs}
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
                    Recent gateway and agent logs from the server.
                </DialogDescription>
                {loading && !logs && !error ? (
                    <div className="flex items-center justify-center py-12">
                        <Loader2 className="size-6 animate-spin text-muted-foreground" />
                    </div>
                ) : error ? (
                    <div className="rounded-lg border border-destructive/50 bg-destructive/10 p-4">
                        <p className="text-sm text-destructive">{error}</p>
                    </div>
                ) : (
                    <pre
                        ref={preRef}
                        className="max-h-96 overflow-auto rounded-lg bg-zinc-950 p-4 font-mono text-xs leading-relaxed text-zinc-200"
                    >
                        {logs}
                    </pre>
                )}
            </DialogContent>
        </Dialog>
    );
}
