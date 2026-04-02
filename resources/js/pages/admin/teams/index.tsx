import { Head, Link, router } from '@inertiajs/react';
import { DollarSign } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import { Pagination } from '@/components/pagination';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '@/layouts/admin-layout';
import type { AdminTeam, BreadcrumbItem, PaginatedData } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin/dashboard' },
    { title: 'Teams', href: '/admin/teams' },
];

function GrantCreditsDialog({ team }: { team: AdminTeam }) {
    const [open, setOpen] = useState(false);
    const [amount, setAmount] = useState('');
    const [reason, setReason] = useState('');
    const [submitting, setSubmitting] = useState(false);

    const handleSubmit = () => {
        if (!amount || parseFloat(amount) <= 0) return;
        setSubmitting(true);
        router.post(
            `/admin/teams/${team.id}/grant-credits`,
            { amount, reason },
            {
                onFinish: () => {
                    setSubmitting(false);
                    setOpen(false);
                    setAmount('');
                    setReason('');
                },
            },
        );
    };

    const balance = team.credit_wallet
        ? (team.credit_wallet.balance_cents / 100).toFixed(2)
        : '0.00';

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="ghost" size="sm" className="h-7 gap-1 text-xs">
                    <DollarSign className="h-3 w-3" />
                    Grant
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Grant credits to {team.name}</DialogTitle>
                    <DialogDescription>
                        Current balance: ${balance}. Credits will be added
                        immediately.
                    </DialogDescription>
                </DialogHeader>
                <div className="grid gap-4 py-4">
                    <div className="grid gap-2">
                        <Label htmlFor="amount">Amount (USD)</Label>
                        <Input
                            id="amount"
                            type="number"
                            min="1"
                            max="10000"
                            step="0.01"
                            placeholder="10.00"
                            value={amount}
                            onChange={(e) => setAmount(e.target.value)}
                        />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="reason">Reason (optional)</Label>
                        <Input
                            id="reason"
                            placeholder="e.g. Beta tester bonus"
                            value={reason}
                            onChange={(e) => setReason(e.target.value)}
                        />
                    </div>
                </div>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="outline">Cancel</Button>
                    </DialogClose>
                    <Button
                        onClick={handleSubmit}
                        disabled={
                            submitting || !amount || parseFloat(amount) <= 0
                        }
                    >
                        {submitting ? 'Granting...' : `Grant $${amount || '0'}`}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default function AdminTeamsIndex({
    teams,
}: {
    teams: PaginatedData<AdminTeam>;
}) {
    return (
        <AdminLayout breadcrumbs={breadcrumbs}>
            <Head title="Teams - Admin" />

            <div className="px-4 py-6 sm:px-6">
                <Heading
                    variant="small"
                    title="Teams"
                    description="All teams across the platform."
                />

                <div className="mt-6">
                    {teams.data.length === 0 ? (
                        <p className="py-12 text-center text-sm text-muted-foreground">
                            No teams yet.
                        </p>
                    ) : (
                        <>
                            <div className="overflow-x-auto rounded-lg border">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b bg-muted/50 text-left">
                                            <th className="px-4 py-2.5 font-medium">
                                                Name
                                            </th>
                                            <th className="px-4 py-2.5 font-medium">
                                                Owner
                                            </th>
                                            <th className="px-4 py-2.5 font-medium">
                                                Plan
                                            </th>
                                            <th className="px-4 py-2.5 font-medium">
                                                Balance
                                            </th>
                                            <th className="px-4 py-2.5 font-medium">
                                                Members
                                            </th>
                                            <th className="px-4 py-2.5 font-medium">
                                                Agents
                                            </th>
                                            <th className="px-4 py-2.5 font-medium">
                                                Server
                                            </th>
                                            <th className="px-4 py-2.5 font-medium">
                                                Actions
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        {teams.data.map((team) => (
                                            <tr
                                                key={team.id}
                                                className="hover:bg-muted/30"
                                            >
                                                <td className="px-4 py-2.5 font-medium">
                                                    {team.name}
                                                </td>
                                                <td className="px-4 py-2.5">
                                                    {team.owner && (
                                                        <Link
                                                            href={`/admin/users/${team.owner.id}`}
                                                            className="text-muted-foreground hover:underline"
                                                        >
                                                            {team.owner.name}
                                                        </Link>
                                                    )}
                                                </td>
                                                <td className="px-4 py-2.5">
                                                    {team.plan ? (
                                                        <Badge variant="outline">
                                                            {team.plan}
                                                        </Badge>
                                                    ) : (
                                                        <span className="text-muted-foreground">
                                                            -
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="px-4 py-2.5 font-mono text-xs">
                                                    {team.credit_wallet ? (
                                                        `$${(team.credit_wallet.balance_cents / 100).toFixed(2)}`
                                                    ) : (
                                                        <span className="text-muted-foreground">
                                                            -
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="px-4 py-2.5">
                                                    {team.members_count}
                                                </td>
                                                <td className="px-4 py-2.5">
                                                    {team.agents_count}
                                                </td>
                                                <td className="px-4 py-2.5">
                                                    {team.server ? (
                                                        <ServerStatusDot
                                                            status={
                                                                team.server
                                                                    .status
                                                            }
                                                        />
                                                    ) : (
                                                        <span className="text-muted-foreground">
                                                            -
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="px-4 py-2.5">
                                                    <GrantCreditsDialog
                                                        team={team}
                                                    />
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                            <Pagination data={teams} />
                        </>
                    )}
                </div>
            </div>
        </AdminLayout>
    );
}

function ServerStatusDot({ status }: { status: string }) {
    const colors: Record<string, string> = {
        provisioning: 'bg-yellow-500',
        setup_complete: 'bg-blue-500',
        running: 'bg-green-500',
        stopped: 'bg-gray-400',
        error: 'bg-red-500',
        destroying: 'bg-orange-500',
    };

    return (
        <span className="flex items-center gap-1.5 text-xs text-muted-foreground">
            <span
                className={`inline-block h-2 w-2 rounded-full ${colors[status] || 'bg-gray-400'}`}
            />
            {status}
        </span>
    );
}
