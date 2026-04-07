import { Head, router } from '@inertiajs/react';
import type { Stripe } from '@stripe/stripe-js';
import {
    AlertCircle,
    Bot,
    Building,
    Check,
    Crown,
    ExternalLink,
    Minus,
    Plus,
    TrendingDown,
    TrendingUp,
    Wallet,
    Zap,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { AutoTopUpCard } from '@/components/billing/auto-topup-card';
import { PaymentMethodsCard } from '@/components/billing/payment-methods-card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import AppLayout from '@/layouts/app-layout';
import { getStripe } from '@/lib/stripe';
import { cn } from '@/lib/utils';
import type {
    BreadcrumbItem,
    CreditTransaction,
    CreditWallet,
    PaymentMethod,
} from '@/types';

function formatCents(cents: number): string {
    return `$${(cents / 100).toFixed(2)}`;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Billing', href: '/billing' }];

type TopUpAmount = {
    cents: number;
    label: string;
};

type PlanInfo = {
    key: string;
    label: string;
    price_cents: number;
    included_agents: number;
    max_agents: number;
    included_credits_cents: number;
    storage_mb: number;
    trial_days: number;
};

const PLAN_ORDER = ['starter', 'team', 'company'];

const PLAN_ICONS: Record<string, React.ReactNode> = {
    starter: <Zap className="size-4" />,
    team: <Crown className="size-4" />,
    company: <Building className="size-4" />,
};

function planFeatures(plan: PlanInfo): string[] {
    const credits = `$${(plan.included_credits_cents / 100).toFixed(0)} AI credits included`;
    const features = [
        `${plan.included_agents} agents included`,
        credits,
        `${plan.storage_mb} MB storage`,
        'All channels',
        `${plan.trial_days}-day free trial`,
    ];
    if (plan.key === 'team' || plan.key === 'company')
        features.push('Priority support');
    return features;
}

export default function BillingIndex({
    wallet,
    transactions,
    agentCount,
    agentLimit,
    includedAgents = 0,
    extraSeats = 0,
    availableExtraSeats = 0,
    agentSeatPriceCents = 2900,
    topUpAmounts,
    hasSubscription,
    currentPlan,
    plans,
    isOnTrial,
    trialEndsAt,
    paymentMethods = [],
    defaultPaymentMethodId = null,
    autoTopUp = null,
}: {
    wallet: CreditWallet | null;
    transactions: CreditTransaction[];
    agentCount: number;
    agentLimit: number;
    includedAgents?: number;
    extraSeats?: number;
    availableExtraSeats?: number;
    agentSeatPriceCents?: number;
    topUpAmounts: TopUpAmount[];
    hasSubscription: boolean;
    currentPlan: string | null;
    plans: PlanInfo[];
    isOnTrial: boolean;
    trialEndsAt: string | null;
    paymentMethods?: PaymentMethod[];
    defaultPaymentMethodId?: string | null;
    autoTopUp?: {
        enabled: boolean;
        threshold_cents: number | null;
        amount_cents: number | null;
    } | null;
}) {
    const [stripeInstance, setStripeInstance] = useState<Stripe | null>(null);
    const [confirmTopUp, setConfirmTopUp] = useState<TopUpAmount | null>(null);
    const [topUpProcessing, setTopUpProcessing] = useState(false);
    const [subscribeProcessing, setSubscribeProcessing] = useState<
        string | null
    >(null);
    const [changePlanProcessing, setChangePlanProcessing] = useState(false);
    const [seatProcessing, setSeatProcessing] = useState(false);

    const checkoutParam = new URLSearchParams(window.location.search).get(
        'checkout',
    );

    useEffect(() => {
        getStripe().then(setStripeInstance);
    }, []);

    const defaultPm = paymentMethods.find(
        (pm) => pm.id === defaultPaymentMethodId,
    );

    const handleTopUp = (amount: TopUpAmount) => {
        if (!defaultPm) return;
        setConfirmTopUp(amount);
    };

    const confirmAndCharge = () => {
        if (!confirmTopUp) return;
        setTopUpProcessing(true);
        router.post(
            '/billing/top-up',
            { amount_cents: confirmTopUp.cents },
            {
                preserveScroll: true,
                onFinish: () => {
                    setTopUpProcessing(false);
                    setConfirmTopUp(null);
                },
            },
        );
    };

    const handleSubscribe = (planKey: string) => {
        setSubscribeProcessing(planKey);
        router.post(
            '/billing/subscribe',
            { plan: planKey },
            { onError: () => setSubscribeProcessing(null) },
        );
    };

    const handleChangePlan = (planKey: string) => {
        setChangePlanProcessing(true);
        router.post(
            '/billing/change-plan',
            { plan: planKey },
            {
                preserveScroll: true,
                onFinish: () => setChangePlanProcessing(false),
            },
        );
    };

    const handleAddSeat = () => {
        setSeatProcessing(true);
        router.post(
            '/billing/agent-seats/add',
            {},
            {
                preserveScroll: true,
                onFinish: () => setSeatProcessing(false),
            },
        );
    };

    const handleRemoveSeat = () => {
        setSeatProcessing(true);
        router.post(
            '/billing/agent-seats/remove',
            {},
            {
                preserveScroll: true,
                onFinish: () => setSeatProcessing(false),
            },
        );
    };

    const currentPlanIndex = PLAN_ORDER.indexOf(currentPlan ?? '');

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Billing" />

            <div className="px-4 py-6 sm:px-6">
                <div className="mx-auto max-w-4xl space-y-6">
                    {/* Trial Banner */}
                    {isOnTrial && trialEndsAt && (
                        <div className="rounded-lg border border-blue-200 bg-blue-50 p-4 dark:border-blue-900 dark:bg-blue-950/50">
                            <p className="text-sm text-blue-800 dark:text-blue-200">
                                Your free trial ends on{' '}
                                <strong>
                                    {new Date(trialEndsAt).toLocaleDateString(
                                        'en-US',
                                        {
                                            month: 'long',
                                            day: 'numeric',
                                            year: 'numeric',
                                        },
                                    )}
                                </strong>
                                . Your card will be charged automatically after
                                the trial.
                            </p>
                        </div>
                    )}

                    {/* Checkout cancelled */}
                    {checkoutParam === 'cancelled' && (
                        <div className="flex items-center gap-2 rounded-lg border border-destructive/50 bg-destructive/10 p-4">
                            <AlertCircle className="size-4 text-destructive" />
                            <p className="text-sm text-destructive">
                                Checkout was cancelled. Please try again.
                            </p>
                        </div>
                    )}

                    {/* Plan Cards */}
                    <div>
                        <h2 className="mb-1 text-lg font-semibold">Plans</h2>
                        <p className="mb-4 text-sm text-muted-foreground">
                            {hasSubscription
                                ? `You're on the ${plans.find((p) => p.key === currentPlan)?.label ?? ''} plan.`
                                : 'Choose a plan to get started. All plans include a free trial.'}
                            {hasSubscription && (
                                <>
                                    {' '}
                                    <a
                                        href="/billing/portal"
                                        className="inline-flex items-center gap-1 font-medium text-foreground underline underline-offset-4 hover:text-primary"
                                    >
                                        Manage billing
                                        <ExternalLink className="size-3" />
                                    </a>
                                </>
                            )}
                        </p>

                        <div className="grid gap-4 md:grid-cols-3">
                            {plans.map((plan) => {
                                const isCurrent = currentPlan === plan.key;
                                const planIndex = PLAN_ORDER.indexOf(plan.key);
                                const isUpgrade =
                                    hasSubscription &&
                                    !isCurrent &&
                                    planIndex > currentPlanIndex;
                                const isDowngrade =
                                    hasSubscription &&
                                    !isCurrent &&
                                    planIndex < currentPlanIndex;
                                const features = planFeatures(plan);

                                return (
                                    <Card
                                        key={plan.key}
                                        className={cn(
                                            'relative',
                                            isCurrent &&
                                                'border-primary ring-1 ring-primary',
                                        )}
                                    >
                                        {isCurrent && (
                                            <Badge className="absolute -top-2.5 left-4 bg-primary text-xs">
                                                Current Plan
                                            </Badge>
                                        )}
                                        <CardHeader className="pb-3">
                                            <CardTitle className="flex items-center gap-2">
                                                {PLAN_ICONS[plan.key]}
                                                {plan.label}
                                            </CardTitle>
                                            <div className="pt-1">
                                                <span className="text-3xl font-bold">
                                                    $
                                                    {(
                                                        plan.price_cents / 100
                                                    ).toFixed(0)}
                                                </span>
                                                <span className="text-muted-foreground">
                                                    /month
                                                </span>
                                            </div>
                                        </CardHeader>
                                        <CardContent className="space-y-4">
                                            <ul className="space-y-2">
                                                {features.map((feature) => (
                                                    <li
                                                        key={feature}
                                                        className="flex items-start gap-2 text-sm"
                                                    >
                                                        <Check className="mt-0.5 size-3.5 shrink-0 text-primary" />
                                                        <span>{feature}</span>
                                                    </li>
                                                ))}
                                                <li className="flex items-start gap-2 text-sm text-muted-foreground">
                                                    <Plus className="mt-0.5 size-3.5 shrink-0" />
                                                    <span>
                                                        Extra agents $29/mo each
                                                    </span>
                                                </li>
                                            </ul>

                                            {!hasSubscription && (
                                                <Button
                                                    className="w-full"
                                                    onClick={() =>
                                                        handleSubscribe(
                                                            plan.key,
                                                        )
                                                    }
                                                    disabled={
                                                        subscribeProcessing !==
                                                        null
                                                    }
                                                >
                                                    {subscribeProcessing ===
                                                    plan.key
                                                        ? 'Redirecting...'
                                                        : 'Start Free Trial'}
                                                </Button>
                                            )}

                                            {hasSubscription && isCurrent && (
                                                <Button
                                                    className="w-full"
                                                    variant="outline"
                                                    disabled
                                                >
                                                    Current Plan
                                                </Button>
                                            )}

                                            {isUpgrade && (
                                                <Button
                                                    className="w-full"
                                                    onClick={() =>
                                                        handleChangePlan(
                                                            plan.key,
                                                        )
                                                    }
                                                    disabled={
                                                        changePlanProcessing
                                                    }
                                                >
                                                    {changePlanProcessing
                                                        ? 'Upgrading...'
                                                        : 'Upgrade'}
                                                </Button>
                                            )}

                                            {isDowngrade && (
                                                <Button
                                                    className="w-full"
                                                    variant="outline"
                                                    onClick={() =>
                                                        handleChangePlan(
                                                            plan.key,
                                                        )
                                                    }
                                                    disabled={
                                                        changePlanProcessing ||
                                                        agentCount >
                                                            plan.max_agents
                                                    }
                                                >
                                                    {agentCount >
                                                    plan.max_agents
                                                        ? `Too many agents`
                                                        : changePlanProcessing
                                                          ? 'Downgrading...'
                                                          : 'Downgrade'}
                                                </Button>
                                            )}
                                        </CardContent>
                                    </Card>
                                );
                            })}
                        </div>
                    </div>

                    {/* Agent Seats */}
                    {hasSubscription && (
                        <Card>
                            <CardHeader>
                                <div className="flex items-center gap-2">
                                    <Bot className="size-5" />
                                    <CardTitle>Agent Seats</CardTitle>
                                </div>
                                <CardDescription>
                                    {agentCount} of {agentLimit} agent slots
                                    used. {includedAgents} included with your
                                    plan
                                    {extraSeats > 0
                                        ? `, ${extraSeats} extra seat${extraSeats !== 1 ? 's' : ''} ($${((extraSeats * agentSeatPriceCents) / 100).toFixed(0)}/mo)`
                                        : ''}
                                    .
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <div className="space-y-4">
                                    <div className="space-y-2">
                                        <div className="flex justify-between text-sm">
                                            <span>{agentCount} agents</span>
                                            <span className="text-muted-foreground">
                                                {agentLimit} slots
                                            </span>
                                        </div>
                                        <div className="h-2 rounded-full bg-muted">
                                            <div
                                                className={cn(
                                                    'h-2 rounded-full transition-all',
                                                    agentCount >= agentLimit
                                                        ? 'bg-destructive'
                                                        : 'bg-primary',
                                                )}
                                                style={{
                                                    width: `${agentLimit > 0 ? Math.min((agentCount / agentLimit) * 100, 100) : 0}%`,
                                                }}
                                            />
                                        </div>
                                    </div>

                                    <div className="flex items-center gap-3">
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={handleRemoveSeat}
                                            disabled={
                                                seatProcessing ||
                                                extraSeats <= 0
                                            }
                                        >
                                            <Minus className="mr-1 size-3.5" />
                                            Remove Seat
                                        </Button>
                                        <Button
                                            size="sm"
                                            onClick={handleAddSeat}
                                            disabled={
                                                seatProcessing ||
                                                availableExtraSeats <= 0
                                            }
                                        >
                                            <Plus className="mr-1 size-3.5" />
                                            Add Seat — $
                                            {(
                                                agentSeatPriceCents / 100
                                            ).toFixed(0)}
                                            /mo
                                        </Button>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    )}

                    {/* Credit Balance */}
                    {wallet && (
                        <Card>
                            <CardHeader>
                                <div className="flex items-center gap-2">
                                    <Wallet className="size-5" />
                                    <CardTitle>Credit Balance</CardTitle>
                                </div>
                            </CardHeader>
                            <CardContent>
                                <div className="grid grid-cols-3 gap-4">
                                    <div>
                                        <p className="text-xs text-muted-foreground">
                                            Current Balance
                                        </p>
                                        <p
                                            className={cn(
                                                'text-xl font-semibold',
                                                wallet.balance_cents < 0 &&
                                                    'text-destructive',
                                            )}
                                        >
                                            {formatCents(wallet.balance_cents)}
                                        </p>
                                    </div>
                                    <div>
                                        <p className="text-xs text-muted-foreground">
                                            Lifetime Credits
                                        </p>
                                        <p className="text-xl font-semibold">
                                            {formatCents(
                                                wallet.lifetime_credits_cents,
                                            )}
                                        </p>
                                    </div>
                                    <div>
                                        <p className="text-xs text-muted-foreground">
                                            Total Usage
                                        </p>
                                        <p className="text-xl font-semibold">
                                            {formatCents(
                                                wallet.lifetime_usage_cents,
                                            )}
                                        </p>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    )}

                    {/* Add Credits */}
                    {wallet && (
                        <Card>
                            <CardHeader>
                                <div className="flex items-center gap-2">
                                    <Plus className="size-5" />
                                    <CardTitle>Add Credits</CardTitle>
                                </div>
                                <CardDescription>
                                    {defaultPm
                                        ? 'Top up your credit balance for AI usage.'
                                        : 'Add a payment method below to enable top-ups.'}
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                                    {topUpAmounts.map((amount) => (
                                        <Button
                                            key={amount.cents}
                                            variant="outline"
                                            className="h-auto flex-col gap-0.5 py-3"
                                            onClick={() => handleTopUp(amount)}
                                            disabled={!defaultPm}
                                        >
                                            <span className="text-base font-semibold">
                                                {amount.label}
                                            </span>
                                            <span className="text-xs text-muted-foreground">
                                                credits
                                            </span>
                                        </Button>
                                    ))}
                                </div>
                            </CardContent>
                        </Card>
                    )}

                    {/* Payment Methods */}
                    {wallet && (
                        <PaymentMethodsCard
                            paymentMethods={paymentMethods}
                            defaultPaymentMethodId={defaultPaymentMethodId}
                            stripeInstance={stripeInstance}
                        />
                    )}

                    {/* Auto Top-Up */}
                    {wallet && (
                        <AutoTopUpCard
                            autoTopUp={autoTopUp}
                            hasDefaultPaymentMethod={!!defaultPm}
                        />
                    )}

                    {/* Recent Transactions */}
                    {transactions.length > 0 && (
                        <Card>
                            <CardHeader>
                                <CardTitle>Recent Transactions</CardTitle>
                                <CardDescription>
                                    Credit additions and usage debits.
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <div className="divide-y">
                                    {transactions.map((tx) => (
                                        <div
                                            key={tx.id}
                                            className="flex items-center justify-between py-3 first:pt-0 last:pb-0"
                                        >
                                            <div className="flex items-center gap-3">
                                                {tx.amount_cents > 0 ? (
                                                    <TrendingUp className="size-4 text-emerald-500" />
                                                ) : (
                                                    <TrendingDown className="size-4 text-muted-foreground" />
                                                )}
                                                <div>
                                                    <p className="text-sm font-medium">
                                                        {tx.type_label}
                                                    </p>
                                                    {tx.description && (
                                                        <p className="text-xs text-muted-foreground">
                                                            {tx.description}
                                                        </p>
                                                    )}
                                                </div>
                                            </div>
                                            <div className="text-right">
                                                <p
                                                    className={cn(
                                                        'text-sm font-medium',
                                                        tx.amount_cents > 0
                                                            ? 'text-emerald-600'
                                                            : 'text-muted-foreground',
                                                    )}
                                                >
                                                    {tx.amount_cents > 0
                                                        ? '+'
                                                        : ''}
                                                    {formatCents(
                                                        tx.amount_cents,
                                                    )}
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    {new Date(
                                                        tx.created_at,
                                                    ).toLocaleDateString()}
                                                </p>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </CardContent>
                        </Card>
                    )}
                </div>
            </div>

            {/* Top-Up Confirmation Dialog */}
            <Dialog
                open={!!confirmTopUp}
                onOpenChange={(open) => !open && setConfirmTopUp(null)}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Confirm Top-Up</DialogTitle>
                        <DialogDescription>
                            {defaultPm && confirmTopUp && (
                                <>
                                    Charge <strong>{confirmTopUp.label}</strong>{' '}
                                    to{' '}
                                    {defaultPm.brand.charAt(0).toUpperCase() +
                                        defaultPm.brand.slice(1)}{' '}
                                    &bull;&bull;&bull;&bull;{' '}
                                    {defaultPm.last_four}?
                                </>
                            )}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setConfirmTopUp(null)}
                            disabled={topUpProcessing}
                        >
                            Cancel
                        </Button>
                        <Button
                            onClick={confirmAndCharge}
                            disabled={topUpProcessing}
                        >
                            {topUpProcessing ? 'Charging...' : 'Confirm'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
