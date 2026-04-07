import { router } from '@inertiajs/react';
import {
    CardElement,
    Elements,
    useElements,
    useStripe,
} from '@stripe/react-stripe-js';
import type { Stripe } from '@stripe/stripe-js';
import { Loader2 } from 'lucide-react';
import { type FormEvent, useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

function AddCardForm({
    clientSecret,
    onSuccess,
    onCancel,
}: {
    clientSecret: string;
    onSuccess: () => void;
    onCancel: () => void;
}) {
    const stripe = useStripe();
    const elements = useElements();
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const handleSubmit = async (e: FormEvent) => {
        e.preventDefault();

        if (!stripe || !elements) {
            return;
        }

        setProcessing(true);
        setError(null);

        const cardElement = elements.getElement(CardElement);

        if (!cardElement) {
            setProcessing(false);
            return;
        }

        const { setupIntent, error: stripeError } =
            await stripe.confirmCardSetup(clientSecret, {
                payment_method: { card: cardElement },
            });

        if (stripeError) {
            setError(stripeError.message ?? 'An error occurred.');
            setProcessing(false);
            return;
        }

        if (setupIntent?.payment_method) {
            router.post(
                '/billing/payment-methods',
                { payment_method_id: setupIntent.payment_method as string },
                {
                    preserveScroll: true,
                    onSuccess: () => onSuccess(),
                    onError: () => {
                        setError('Failed to save payment method.');
                        setProcessing(false);
                    },
                },
            );
        }
    };

    return (
        <form onSubmit={handleSubmit}>
            <div className="rounded-md border p-3">
                <CardElement
                    options={{
                        style: {
                            base: {
                                fontSize: '16px',
                                color: '#f0f0f0',
                                iconColor: '#a0a0a0',
                                '::placeholder': {
                                    color: '#666',
                                },
                            },
                            invalid: {
                                color: '#ef4444',
                            },
                        },
                    }}
                />
            </div>
            {error && <p className="mt-2 text-sm text-destructive">{error}</p>}
            <DialogFooter className="mt-4">
                <Button
                    type="button"
                    variant="outline"
                    onClick={onCancel}
                    disabled={processing}
                >
                    Cancel
                </Button>
                <Button type="submit" disabled={processing || !stripe}>
                    {processing && (
                        <Loader2 className="mr-2 size-4 animate-spin" />
                    )}
                    Add Card
                </Button>
            </DialogFooter>
        </form>
    );
}

export function AddCardDialog({
    open,
    onOpenChange,
    stripeInstance,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    stripeInstance: Stripe | null;
}) {
    const [clientSecret, setClientSecret] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);
    const [fetchError, setFetchError] = useState<string | null>(null);

    useEffect(() => {
        if (!open || clientSecret) return;

        let cancelled = false;

        (async () => {
            setLoading(true);
            setFetchError(null);
            try {
                const response = await fetch(
                    '/billing/payment-methods/setup-intent',
                    {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            Accept: 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-XSRF-TOKEN': decodeURIComponent(
                                document.cookie
                                    .split('; ')
                                    .find((row) =>
                                        row.startsWith('XSRF-TOKEN='),
                                    )
                                    ?.split('=')[1] ?? '',
                            ),
                        },
                    },
                );
                if (!response.ok) {
                    throw new Error(`Server error (${response.status})`);
                }
                const data = await response.json();
                if (!cancelled) setClientSecret(data.client_secret);
            } catch (err) {
                if (!cancelled) {
                    setFetchError(
                        err instanceof Error
                            ? err.message
                            : 'Failed to initialize payment form.',
                    );
                }
            }
            if (!cancelled) setLoading(false);
        })();

        return () => {
            cancelled = true;
        };
    }, [open, clientSecret]);

    const handleSuccess = () => {
        setClientSecret(null);
        onOpenChange(false);
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Add Payment Method</DialogTitle>
                    <DialogDescription>
                        Add a credit or debit card to your account.
                    </DialogDescription>
                </DialogHeader>
                {loading ? (
                    <div className="flex items-center justify-center py-8">
                        <Loader2 className="size-6 animate-spin text-muted-foreground" />
                    </div>
                ) : fetchError ? (
                    <p className="py-4 text-sm text-destructive">
                        {fetchError}
                    </p>
                ) : clientSecret && stripeInstance ? (
                    <Elements stripe={stripeInstance}>
                        <AddCardForm
                            clientSecret={clientSecret}
                            onSuccess={handleSuccess}
                            onCancel={() => onOpenChange(false)}
                        />
                    </Elements>
                ) : !stripeInstance ? (
                    <p className="py-4 text-sm text-muted-foreground">
                        Loading payment form...
                    </p>
                ) : null}
            </DialogContent>
        </Dialog>
    );
}
