import { useForm } from '@inertiajs/react';
import { RefreshCw } from 'lucide-react';
import { type FormEvent, useEffect } from 'react';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type AutoTopUpSettings = {
    enabled: boolean;
    threshold_cents: number | null;
    amount_cents: number | null;
};

export function AutoTopUpCard({
    autoTopUp,
    hasDefaultPaymentMethod,
}: {
    autoTopUp: AutoTopUpSettings | null;
    hasDefaultPaymentMethod: boolean;
}) {
    const form = useForm({
        enabled: autoTopUp?.enabled ?? false,
        threshold_cents: autoTopUp?.threshold_cents ?? 500,
        amount_cents: autoTopUp?.amount_cents ?? 2500,
    });

    useEffect(() => {
        form.setData({
            enabled: autoTopUp?.enabled ?? false,
            threshold_cents: autoTopUp?.threshold_cents ?? 500,
            amount_cents: autoTopUp?.amount_cents ?? 2500,
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [autoTopUp]);

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        form.put('/billing/auto-topup', { preserveScroll: true });
    };

    const thresholdDollars = (form.data.threshold_cents / 100).toFixed(0);
    const amountDollars = (form.data.amount_cents / 100).toFixed(0);

    return (
        <Card>
            <CardHeader>
                <div className="flex items-center gap-2">
                    <RefreshCw className="size-5" />
                    <CardTitle>Auto Top-Up</CardTitle>
                </div>
                <CardDescription>
                    Automatically add credits when your balance drops below a
                    threshold.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <form onSubmit={handleSubmit} className="space-y-4">
                    <div className="flex items-center gap-2">
                        <Checkbox
                            id="auto-topup-enabled"
                            checked={form.data.enabled}
                            onCheckedChange={(checked) =>
                                form.setData('enabled', checked === true)
                            }
                            disabled={
                                !hasDefaultPaymentMethod && !form.data.enabled
                            }
                        />
                        <Label htmlFor="auto-topup-enabled">
                            Enable auto top-up
                        </Label>
                    </div>

                    {!hasDefaultPaymentMethod && !form.data.enabled && (
                        <p className="text-xs text-muted-foreground">
                            Add a payment method to enable auto top-up.
                        </p>
                    )}

                    {form.data.enabled && (
                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-2">
                                <Label htmlFor="threshold">
                                    When balance drops below
                                </Label>
                                <div className="relative">
                                    <span className="absolute top-1/2 left-3 -translate-y-1/2 text-sm text-muted-foreground">
                                        $
                                    </span>
                                    <Input
                                        id="threshold"
                                        type="number"
                                        min="1"
                                        className="pl-7"
                                        value={thresholdDollars}
                                        onChange={(e) =>
                                            form.setData(
                                                'threshold_cents',
                                                Number(e.target.value) * 100,
                                            )
                                        }
                                    />
                                </div>
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="amount">Charge amount</Label>
                                <div className="relative">
                                    <span className="absolute top-1/2 left-3 -translate-y-1/2 text-sm text-muted-foreground">
                                        $
                                    </span>
                                    <Input
                                        id="amount"
                                        type="number"
                                        min="5"
                                        className="pl-7"
                                        value={amountDollars}
                                        onChange={(e) =>
                                            form.setData(
                                                'amount_cents',
                                                Number(e.target.value) * 100,
                                            )
                                        }
                                    />
                                </div>
                            </div>
                        </div>
                    )}

                    {form.errors.enabled && (
                        <p className="text-sm text-destructive">
                            {form.errors.enabled}
                        </p>
                    )}

                    <Button type="submit" size="sm" disabled={form.processing}>
                        Save
                    </Button>
                </form>
            </CardContent>
        </Card>
    );
}
