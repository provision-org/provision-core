import { Head, router, usePage } from '@inertiajs/react';
import { Copy, Key, Trash2 } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import type { BreadcrumbItem } from '@/types';

type Token = {
    id: string;
    name: string;
    last_used_at: string | null;
    created_at: string;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'API Tokens', href: '/settings/api' },
];

export default function ApiTokens({ tokens }: { tokens: Token[] }) {
    const { props } = usePage();
    const flash = props.flash as Record<string, string> | undefined;
    const newToken = flash?.newToken ?? null;
    const [name, setName] = useState('');
    const [processing, setProcessing] = useState(false);
    const [copied, setCopied] = useState(false);

    function createToken(e: React.FormEvent) {
        e.preventDefault();
        setProcessing(true);
        router.post(
            '/settings/api',
            { name },
            {
                preserveScroll: true,
                onFinish: () => {
                    setProcessing(false);
                    setName('');
                },
            },
        );
    }

    function deleteToken(id: string) {
        if (
            !confirm('Are you sure? This token will stop working immediately.')
        ) {
            return;
        }
        router.delete(`/settings/api/${id}`, { preserveScroll: true });
    }

    function copyToClipboard(text: string) {
        navigator.clipboard.writeText(text);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="API Tokens" />

            <SettingsLayout>
                <div className="space-y-6">
                    <Heading
                        variant="small"
                        title="API Tokens"
                        description="Create tokens to authenticate with the Provision CLI and API."
                    />

                    {/* New token display */}
                    {newToken && (
                        <div className="rounded-lg border border-green-200 bg-green-50 p-4 dark:border-green-900 dark:bg-green-950/30">
                            <p className="mb-2 text-sm font-medium text-green-800 dark:text-green-200">
                                Your new API token. Copy it now — you won't see
                                it again.
                            </p>
                            <div className="flex items-center gap-2">
                                <code className="flex-1 rounded bg-green-100 px-3 py-2 font-mono text-sm text-green-900 dark:bg-green-900/50 dark:text-green-100">
                                    {newToken}
                                </code>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() => copyToClipboard(newToken)}
                                >
                                    {copied ? (
                                        'Copied!'
                                    ) : (
                                        <Copy className="size-4" />
                                    )}
                                </Button>
                            </div>
                        </div>
                    )}

                    {/* Create token form */}
                    <form
                        onSubmit={createToken}
                        className="flex items-end gap-3"
                    >
                        <div className="flex-1">
                            <Label htmlFor="token-name">Token name</Label>
                            <Input
                                id="token-name"
                                value={name}
                                onChange={(e) => setName(e.target.value)}
                                placeholder="e.g. provision-cli, ci-pipeline"
                                className="mt-1"
                                required
                            />
                        </div>
                        <Button
                            type="submit"
                            disabled={processing || !name.trim()}
                        >
                            {processing ? 'Creating...' : 'Create Token'}
                        </Button>
                    </form>

                    {/* Existing tokens */}
                    {tokens.length > 0 && (
                        <div className="border-t pt-6">
                            <h3 className="mb-4 text-sm font-medium">
                                Active tokens
                            </h3>
                            <div className="space-y-3">
                                {tokens.map((token) => (
                                    <div
                                        key={token.id}
                                        className="flex items-center justify-between rounded-lg border border-border px-4 py-3"
                                    >
                                        <div className="flex items-center gap-3">
                                            <Key className="size-4 text-muted-foreground" />
                                            <div>
                                                <p className="text-sm font-medium">
                                                    {token.name}
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    Created{' '}
                                                    {new Date(
                                                        token.created_at,
                                                    ).toLocaleDateString()}
                                                    {token.last_used_at
                                                        ? ` · Last used ${new Date(token.last_used_at).toLocaleDateString()}`
                                                        : ' · Never used'}
                                                </p>
                                            </div>
                                        </div>
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            onClick={() =>
                                                deleteToken(token.id)
                                            }
                                            className="text-muted-foreground hover:text-destructive"
                                        >
                                            <Trash2 className="size-4" />
                                        </Button>
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}

                    {tokens.length === 0 && !newToken && (
                        <p className="text-sm text-muted-foreground">
                            No API tokens yet. Create one to use the Provision
                            CLI.
                        </p>
                    )}
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
