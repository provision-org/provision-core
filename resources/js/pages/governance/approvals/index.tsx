import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Check, Clock, ShieldCheck, X } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import { useEcho } from '@/hooks/use-echo';
import type { SharedData } from '@/types';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import type { Approval, BreadcrumbItem, Team } from '@/types';

type Tab = 'pending' | 'approved' | 'rejected' | 'all';

const TYPE_COLORS: Record<string, string> = {
    hire_agent:
        'bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300',
    external_action:
        'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
    strategy_proposal:
        'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
};

const STATUS_COLORS: Record<string, string> = {
    pending:
        'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-300',
    approved:
        'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300',
    rejected: 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
    revision_requested:
        'bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-300',
};

function relativeTime(dateStr: string): string {
    const diff = Date.now() - new Date(dateStr).getTime();
    const mins = Math.floor(diff / 60000);
    if (mins < 1) {
        return 'just now';
    }
    if (mins < 60) {
        return `${mins}m ago`;
    }
    const hours = Math.floor(mins / 60);
    if (hours < 24) {
        return `${hours}h ago`;
    }
    const days = Math.floor(hours / 24);
    return `${days}d ago`;
}

function ReviewDialog({
    approval,
    action,
    open,
    onOpenChange,
}: {
    approval: Approval;
    action: 'approved' | 'rejected';
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const form = useForm({ review_note: '' });

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        const endpoint = action === 'approved'
            ? `/governance/approvals/${approval.id}/approve`
            : `/governance/approvals/${approval.id}/reject`;
        form.post(endpoint, {
            onSuccess: () => onOpenChange(false),
        });
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        {action === 'approved'
                            ? 'Approve Request'
                            : 'Reject Request'}
                    </DialogTitle>
                    <DialogDescription>{approval.title}</DialogDescription>
                </DialogHeader>
                <form onSubmit={handleSubmit} className="space-y-4">
                    <Textarea
                        placeholder="Add a note (optional)"
                        value={form.data.review_note}
                        onChange={(e) =>
                            form.setData('review_note', e.target.value)
                        }
                        rows={3}
                    />
                    <DialogFooter>
                        <Button
                            variant="outline"
                            type="button"
                            onClick={() => onOpenChange(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            variant={
                                action === 'approved'
                                    ? 'default'
                                    : 'destructive'
                            }
                            disabled={form.processing}
                        >
                            {action === 'approved' ? 'Approve' : 'Reject'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function ApprovalCard({ approval }: { approval: Approval }) {
    const [reviewAction, setReviewAction] = useState<
        'approved' | 'rejected' | null
    >(null);

    return (
        <>
            <div className="rounded-lg border bg-card p-4 shadow-xs">
                <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0 flex-1">
                        <div className="mb-2 flex flex-wrap items-center gap-2">
                            <Badge
                                className={`text-[10px] ${TYPE_COLORS[approval.type] ?? ''}`}
                            >
                                {approval.type.replace(/_/g, ' ')}
                            </Badge>
                            <Badge
                                className={`text-[10px] ${STATUS_COLORS[approval.status] ?? ''}`}
                            >
                                {approval.status.replace(/_/g, ' ')}
                            </Badge>
                        </div>
                        <p className="text-sm font-medium">{approval.title}</p>
                        <div className="mt-1 flex items-center gap-3 text-xs text-muted-foreground">
                            {approval.requesting_agent && (
                                <span>
                                    From {approval.requesting_agent.name}
                                </span>
                            )}
                            <span className="flex items-center gap-1">
                                <Clock className="size-3" />
                                {relativeTime(approval.created_at)}
                            </span>
                        </div>
                        {approval.review_note && (
                            <p className="mt-2 rounded bg-muted/50 px-3 py-2 text-xs text-muted-foreground">
                                {approval.review_note}
                            </p>
                        )}
                    </div>

                    {approval.status === 'pending' && (
                        <div className="flex shrink-0 gap-2">
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => setReviewAction('approved')}
                            >
                                <Check className="mr-1 size-3.5" />
                                Approve
                            </Button>
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => setReviewAction('rejected')}
                            >
                                <X className="mr-1 size-3.5" />
                                Reject
                            </Button>
                        </div>
                    )}
                </div>
            </div>

            {reviewAction && (
                <ReviewDialog
                    approval={approval}
                    action={reviewAction}
                    open={!!reviewAction}
                    onOpenChange={(open) => {
                        if (!open) {
                            setReviewAction(null);
                        }
                    }}
                />
            )}
        </>
    );
}

export default function ApprovalsIndex({
    approvals,
}: {
    approvals: Approval[];
    team: Team;
}) {
    const { auth } = usePage<SharedData>().props;
    const teamId = auth.user.current_team_id;

    // Real-time approval updates via Reverb
    useEcho(`team.${teamId}`, '.approval.requested', () => {
        router.reload({ only: ['approvals'] });
    });
    useEcho(`team.${teamId}`, '.approval.resolved', () => {
        router.reload({ only: ['approvals'] });
    });

    const [tab, setTab] = useState<Tab>('pending');

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Governance', href: '/governance/tasks' },
        { title: 'Approvals', href: '/governance/approvals' },
    ];

    const pendingCount = approvals.filter((a) => a.status === 'pending').length;

    const filtered =
        tab === 'all' ? approvals : approvals.filter((a) => a.status === tab);

    const tabs: { key: Tab; label: string; count?: number }[] = [
        { key: 'pending', label: 'Pending', count: pendingCount },
        {
            key: 'approved',
            label: 'Approved',
            count: approvals.filter((a) => a.status === 'approved').length,
        },
        {
            key: 'rejected',
            label: 'Rejected',
            count: approvals.filter((a) => a.status === 'rejected').length,
        },
        { key: 'all', label: 'All', count: approvals.length },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Approvals" />

            <div className="px-4 py-6 sm:px-6">
                <Heading
                    variant="small"
                    title="Approvals"
                    description="Review and respond to agent requests."
                />

                {/* Tabs */}
                <div className="mt-4 flex gap-1 border-b">
                    {tabs.map((t) => (
                        <button
                            key={t.key}
                            onClick={() => setTab(t.key)}
                            className={`flex items-center gap-1.5 border-b-2 px-4 py-2 text-sm font-medium transition-colors ${
                                tab === t.key
                                    ? 'border-primary text-foreground'
                                    : 'border-transparent text-muted-foreground hover:text-foreground'
                            }`}
                        >
                            {t.label}
                            {t.count !== undefined && t.count > 0 && (
                                <span className="rounded-full bg-muted px-1.5 py-0.5 text-[10px]">
                                    {t.count}
                                </span>
                            )}
                        </button>
                    ))}
                </div>

                {/* List */}
                {filtered.length === 0 ? (
                    <div className="mt-8 flex flex-col items-center rounded-lg border border-dashed py-16 text-center">
                        <ShieldCheck className="size-10 text-muted-foreground/40" />
                        <p className="mt-4 text-sm text-muted-foreground">
                            No {tab === 'all' ? '' : tab} approvals.
                        </p>
                    </div>
                ) : (
                    <div className="mt-4 space-y-3">
                        {filtered.map((approval) => (
                            <ApprovalCard
                                key={approval.id}
                                approval={approval}
                            />
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
