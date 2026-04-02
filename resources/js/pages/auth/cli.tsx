import { Head } from '@inertiajs/react';
import { Check, Loader2 } from 'lucide-react';
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

export default function CliAuth({
    state,
    port,
}: {
    state: string;
    port: string;
}) {
    const [status, setStatus] = useState<
        'ready' | 'authorizing' | 'success' | 'error'
    >('ready');
    const [error, setError] = useState('');

    async function handleAuthorize() {
        setStatus('authorizing');

        try {
            // Step 1: Create the token on the server
            const response = await fetch('/auth/cli', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({ state, port }),
            });

            if (!response.ok) {
                throw new Error('Failed to create token');
            }

            const data = await response.json();

            // Step 2: Send the token to the CLI's local server
            try {
                await fetch(
                    `http://127.0.0.1:${port}/callback?token=${data.token}&state=${data.state}`,
                    { mode: 'no-cors' },
                );
            } catch {
                // no-cors fetch won't return a readable response, but that's fine
                // the CLI server received the request
            }

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
            <Head title="Authorize CLI" />
            <div className="w-full max-w-sm text-center">
                <div className="mx-auto mb-6 flex size-12 items-center justify-center">
                    <AppLogoIcon className="size-10" />
                </div>

                {status === 'ready' && (
                    <>
                        <h1 className="text-xl font-bold">
                            Authorize Provision CLI
                        </h1>
                        <p className="mt-2 text-sm text-muted-foreground">
                            The Provision CLI is requesting access to your
                            account.
                        </p>
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
                            This will create an API token for CLI access.
                        </p>
                    </>
                )}

                {status === 'authorizing' && (
                    <>
                        <Loader2 className="mx-auto mb-4 size-8 animate-spin text-primary" />
                        <h1 className="text-xl font-bold">Authorizing...</h1>
                        <p className="mt-2 text-sm text-muted-foreground">
                            Connecting to CLI...
                        </p>
                    </>
                )}

                {status === 'success' && (
                    <>
                        <div className="mx-auto mb-4 flex size-12 items-center justify-center rounded-full bg-green-100 dark:bg-green-900/30">
                            <Check className="size-6 text-green-600 dark:text-green-400" />
                        </div>
                        <h1 className="text-xl font-bold">Authenticated!</h1>
                        <p className="mt-2 text-sm text-muted-foreground">
                            You can close this page and return to your terminal.
                        </p>
                        <Button
                            variant="outline"
                            onClick={() => window.close()}
                            className="mt-6 w-full"
                        >
                            Close
                        </Button>
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
