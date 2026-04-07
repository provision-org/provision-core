import { router } from '@inertiajs/react';
import type { Stripe } from '@stripe/stripe-js';
import { CreditCard, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { AddCardDialog } from '@/components/billing/add-card-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import type { PaymentMethod } from '@/types';

function brandLabel(brand: string): string {
    const labels: Record<string, string> = {
        visa: 'Visa',
        mastercard: 'Mastercard',
        amex: 'Amex',
        discover: 'Discover',
        jcb: 'JCB',
        diners: 'Diners',
        unionpay: 'UnionPay',
    };
    return labels[brand] ?? brand.charAt(0).toUpperCase() + brand.slice(1);
}

export function PaymentMethodsCard({
    paymentMethods,
    defaultPaymentMethodId,
    stripeInstance,
}: {
    paymentMethods: PaymentMethod[];
    defaultPaymentMethodId: string | null;
    stripeInstance: Stripe | null;
}) {
    const [dialogOpen, setDialogOpen] = useState(false);

    const handleSetDefault = (id: string) => {
        router.put(
            `/billing/payment-methods/${id}/default`,
            {},
            {
                preserveScroll: true,
            },
        );
    };

    const handleRemove = (id: string) => {
        router.delete(`/billing/payment-methods/${id}`, {
            preserveScroll: true,
        });
    };

    return (
        <>
            <Card>
                <CardHeader>
                    <div className="flex items-center justify-between">
                        <div>
                            <div className="flex items-center gap-2">
                                <CreditCard className="size-5" />
                                <CardTitle>Payment Methods</CardTitle>
                            </div>
                            <CardDescription className="mt-1.5">
                                Manage your saved payment methods.
                            </CardDescription>
                        </div>
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => setDialogOpen(true)}
                        >
                            <Plus className="mr-1 size-3.5" />
                            Add Card
                        </Button>
                    </div>
                </CardHeader>
                <CardContent>
                    {paymentMethods.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No payment methods on file. Add a card to enable
                            credit top-ups.
                        </p>
                    ) : (
                        <div className="divide-y">
                            {paymentMethods.map((pm) => {
                                const isDefault =
                                    pm.id === defaultPaymentMethodId;
                                return (
                                    <div
                                        key={pm.id}
                                        className="flex items-center justify-between py-3 first:pt-0 last:pb-0"
                                    >
                                        <div className="flex items-center gap-3">
                                            <CreditCard className="size-4 text-muted-foreground" />
                                            <div>
                                                <p className="text-sm font-medium">
                                                    {brandLabel(pm.brand)}{' '}
                                                    &bull;&bull;&bull;&bull;{' '}
                                                    {pm.last_four}
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    Expires {pm.exp_month}/
                                                    {pm.exp_year}
                                                </p>
                                            </div>
                                            {isDefault && (
                                                <Badge variant="secondary">
                                                    Default
                                                </Badge>
                                            )}
                                        </div>
                                        <div className="flex items-center gap-2">
                                            {!isDefault && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        handleSetDefault(pm.id)
                                                    }
                                                >
                                                    Set Default
                                                </Button>
                                            )}
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() =>
                                                    handleRemove(pm.id)
                                                }
                                            >
                                                <Trash2 className="size-3.5 text-muted-foreground" />
                                            </Button>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    )}
                </CardContent>
            </Card>

            <AddCardDialog
                open={dialogOpen}
                onOpenChange={setDialogOpen}
                stripeInstance={stripeInstance}
            />
        </>
    );
}
