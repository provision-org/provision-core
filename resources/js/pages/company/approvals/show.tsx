import { Head, Link, useForm } from '@inertiajs/react';
import { Check, Clock, X } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import type { Approval, BreadcrumbItem } from '@/types';

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

export default function ApprovalShow({ approval }: { approval: Approval }) {
    const form = useForm({ status: '' as string, review_note: '' });

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Governance', href: '/company/tasks' },
        { title: 'Approvals', href: '/company/approvals' },
        { title: approval.title, href: `/company/approvals/${approval.id}` },
    ];

    function handleReview(action: 'approved' | 'rejected') {
        form.transform((data) => ({
            ...data,
            status: action,
        }));
        form.patch(`/company/approvals/${approval.id}`);
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={approval.title} />

            <div className="px-4 py-6 sm:px-6">
                {/* Header */}
                <div className="flex flex-wrap items-center gap-2">
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

                <h1 className="mt-3 text-xl font-semibold tracking-tight">
                    {approval.title}
                </h1>

                <div className="mt-3 flex flex-wrap gap-x-6 gap-y-2 text-sm text-muted-foreground">
                    {approval.requesting_agent && (
                        <span>
                            Requested by{' '}
                            <Link
                                href={`/agents/${approval.requesting_agent.id}`}
                                className="font-medium text-foreground hover:underline"
                            >
                                {approval.requesting_agent.name}
                            </Link>
                        </span>
                    )}
                    <span className="flex items-center gap-1">
                        <Clock className="size-3.5" />
                        {new Date(approval.created_at).toLocaleString()}
                    </span>
                    {approval.expires_at && (
                        <span>
                            Expires{' '}
                            {new Date(approval.expires_at).toLocaleString()}
                        </span>
                    )}
                </div>

                {/* Payload */}
                {approval.payload &&
                    Object.keys(approval.payload).length > 0 && (
                        <div className="mt-6">
                            <h2 className="mb-2 text-sm font-medium">
                                Request Details
                            </h2>
                            <div className="rounded-lg border bg-muted/30 p-4">
                                <dl className="space-y-2 text-sm">
                                    {Object.entries(approval.payload).map(
                                        ([key, value]) => (
                                            <div
                                                key={key}
                                                className="flex gap-2"
                                            >
                                                <dt className="shrink-0 font-medium text-muted-foreground">
                                                    {key}:
                                                </dt>
                                                <dd>
                                                    {typeof value === 'string'
                                                        ? value
                                                        : JSON.stringify(value)}
                                                </dd>
                                            </div>
                                        ),
                                    )}
                                </dl>
                            </div>
                        </div>
                    )}

                {/* Linked task */}
                {approval.linked_task && (
                    <div className="mt-6">
                        <h2 className="mb-2 text-sm font-medium">
                            Linked Task
                        </h2>
                        <Link
                            href={`/company/tasks/${approval.linked_task.id}`}
                            className="inline-flex items-center gap-2 rounded-lg border px-4 py-2 text-sm hover:bg-muted/50"
                        >
                            <Badge
                                variant="outline"
                                className="font-mono text-[10px]"
                            >
                                {approval.linked_task.identifier}
                            </Badge>
                            {approval.linked_task.title}
                        </Link>
                    </div>
                )}

                {/* Review note (if already reviewed) */}
                {approval.review_note && (
                    <div className="mt-6">
                        <h2 className="mb-2 text-sm font-medium">
                            Review Note
                        </h2>
                        <p className="rounded-lg border bg-muted/30 p-4 text-sm">
                            {approval.review_note}
                        </p>
                    </div>
                )}

                {/* Review form */}
                {approval.status === 'pending' && (
                    <div className="mt-6 rounded-lg border p-4">
                        <h2 className="mb-3 text-sm font-medium">
                            Review this request
                        </h2>
                        <Textarea
                            placeholder="Add a note (optional)"
                            value={form.data.review_note}
                            onChange={(e) =>
                                form.setData('review_note', e.target.value)
                            }
                            rows={3}
                        />
                        <div className="mt-3 flex gap-2">
                            <Button
                                onClick={() => handleReview('approved')}
                                disabled={form.processing}
                            >
                                <Check className="mr-1.5 size-3.5" />
                                Approve
                            </Button>
                            <Button
                                variant="destructive"
                                onClick={() => handleReview('rejected')}
                                disabled={form.processing}
                            >
                                <X className="mr-1.5 size-3.5" />
                                Reject
                            </Button>
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
