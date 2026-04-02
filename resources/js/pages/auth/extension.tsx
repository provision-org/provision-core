import { Head } from '@inertiajs/react';
import { Check, Loader2, Monitor } from 'lucide-react';
import { useState } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import { Button } from '@/components/ui/button';

function csrfToken(): string {
    return decodeURIComponent(
        document.cookie
            .split('; ')
            .find((c) => c.startsWith('XSRF-TOKEN='))
            ?.split('=')
            .slice(1)
            .join('=') ?? '',
    );
}

export default function ExtensionAuth() {
    const [status, setStatus] = useState<
        'ready' | 'authorizing' | 'success' | 'error'
    >('ready');
    const [error, setError] = useState('');

    async function handleAuthorize() {
        setStatus('authorizing');

        try {
            const response = await fetch('/auth/extension', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': csrfToken(),
                },
            });

            if (!response.ok) {
                throw new Error('Failed to create token');
            }

            const data = await response.json();

            // Redirect to callback URL that the extension listens for
            window.location.href = `/auth/extension/callback?token=${encodeURIComponent(data.token)}&name=${encodeURIComponent(data.name)}&email=${encodeURIComponent(data.email)}`;

            setStatus('success');
        } catch (err) {
            setStatus('error');
            setError(
                err instanceof Error ? err.message : 'Something went wrong',
            );
        }
    }

    return (
        <div className="flex min-h-svh items-center justify-center bg-background">
            <Head title="Authorize Chrome Extension" />
            <div className="w-full max-w-sm text-center">
                <div className="mx-auto mb-6 flex size-12 items-center justify-center">
                    <AppLogoIcon className="size-10" />
                </div>

                {status === 'ready' && (
                    <>
                        <h1 className="text-xl font-bold">
                            Authorize Provision Extension
                        </h1>
                        <p className="mt-2 text-sm text-muted-foreground">
                            The Provision Chrome extension is requesting access
                            to your account.
                        </p>
                        <div className="mt-4 rounded-lg border border-border bg-muted/50 p-4">
                            <div className="flex items-center justify-center gap-2 text-sm text-muted-foreground">
                                <Monitor className="size-4" />
                                Record your screen and create skills
                            </div>
                        </div>
                        <div className="mt-6 flex flex-col gap-3">
                            <Button
                                onClick={handleAuthorize}
                                className="w-full"
                            >
                                Authorize
                            </Button>
                            <Button
                                variant="outline"
                                onClick={() => window.close()}
                                className="w-full"
                            >
                                Cancel
                            </Button>
                        </div>
                        <p className="mt-4 text-xs text-muted-foreground">
                            This will create an API token for the extension.
                        </p>
                    </>
                )}

                {status === 'authorizing' && (
                    <>
                        <Loader2 className="mx-auto mb-4 size-8 animate-spin text-primary" />
                        <h1 className="text-xl font-bold">Authorizing...</h1>
                    </>
                )}

                {status === 'success' && (
                    <>
                        <div className="mx-auto mb-4 flex size-12 items-center justify-center rounded-full bg-green-100 dark:bg-green-900/30">
                            <Check className="size-6 text-green-600 dark:text-green-400" />
                        </div>
                        <h1 className="text-xl font-bold">Connected!</h1>
                        <p className="mt-2 text-sm text-muted-foreground">
                            The extension is now connected. This tab will close
                            automatically.
                        </p>
                    </>
                )}

                {status === 'error' && (
                    <>
                        <h1 className="text-xl font-bold text-destructive">
                            Authorization Failed
                        </h1>
                        <p className="mt-2 text-sm text-muted-foreground">
                            {error}
                        </p>
                        <Button
                            onClick={handleAuthorize}
                            className="mt-6 w-full"
                        >
                            Try Again
                        </Button>
                    </>
                )}
            </div>
        </div>
    );
}
