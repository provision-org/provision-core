import { Head, router, useForm } from '@inertiajs/react';
import { type FormEvent, useState } from 'react';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem, Team } from '@/types';

type ApiKeyItem = {
    id: number;
    provider: string;
    masked_key: string;
    is_active: boolean;
    created_at: string;
    updated_at: string;
};

type EnvVarItem = {
    id: number;
    key: string;
    value_preview: string;
    is_secret: boolean;
    is_system: boolean;
    created_at: string;
    updated_at: string;
};

const providerLabels: Record<string, string> = {
    anthropic: 'Anthropic',
    openai: 'OpenAI',
    open_router: 'OpenRouter',
};

export default function ApiKeysIndex({
    apiKeys,
    envVars,
}: {
    team: Team;
    apiKeys: ApiKeyItem[];
    envVars: EnvVarItem[];
}) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Models & Keys', href: '/api-keys' },
    ];

    const [addKeyOpen, setAddKeyOpen] = useState(false);
    const [addEnvVarOpen, setAddEnvVarOpen] = useState(false);

    const apiKeyForm = useForm({
        provider: 'anthropic',
        api_key: '',
    });

    const envVarForm = useForm({
        key: '',
        value: '',
        is_secret: false as boolean,
    });

    const submitApiKey = (e: FormEvent) => {
        e.preventDefault();
        apiKeyForm.post('/api-keys', {
            preserveScroll: true,
            onSuccess: () => {
                setAddKeyOpen(false);
                apiKeyForm.reset();
            },
        });
    };

    const submitEnvVar = (e: FormEvent) => {
        e.preventDefault();
        envVarForm.post('/api-keys/env-vars', {
            preserveScroll: true,
            onSuccess: () => {
                setAddEnvVarOpen(false);
                envVarForm.reset();
            },
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Models & Keys" />

            <div className="px-4 py-6 sm:px-6">
                <div className="mx-auto max-w-2xl space-y-6">
                    {/* BYOK API Keys */}
                    <Card>
                        <CardHeader className="flex-row items-center justify-between">
                            <div className="flex flex-col gap-1.5">
                                <CardTitle>LLM Provider Keys</CardTitle>
                                <CardDescription>
                                    Optionally use your own API keys instead of
                                    managed credits.
                                </CardDescription>
                            </div>

                            <Dialog
                                open={addKeyOpen}
                                onOpenChange={setAddKeyOpen}
                            >
                                <DialogTrigger asChild>
                                    <Button size="sm">Add Key</Button>
                                </DialogTrigger>
                                <DialogContent>
                                    <DialogTitle>Add API key</DialogTitle>
                                    <DialogDescription>
                                        Add or update an API key for a provider.
                                        If a key already exists for the
                                        provider, it will be replaced.
                                    </DialogDescription>

                                    <form
                                        onSubmit={submitApiKey}
                                        className="space-y-4"
                                    >
                                        <div className="grid gap-2">
                                            <Label htmlFor="provider">
                                                Provider
                                            </Label>
                                            <select
                                                id="provider"
                                                value={apiKeyForm.data.provider}
                                                onChange={(e) =>
                                                    apiKeyForm.setData(
                                                        'provider',
                                                        e.target.value,
                                                    )
                                                }
                                                className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs transition-[color,box-shadow] outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                            >
                                                <option value="anthropic">
                                                    Anthropic
                                                </option>
                                                <option value="openai">
                                                    OpenAI
                                                </option>
                                                <option value="open_router">
                                                    OpenRouter
                                                </option>
                                            </select>
                                            <InputError
                                                message={
                                                    apiKeyForm.errors.provider
                                                }
                                            />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="api_key">
                                                API Key
                                            </Label>
                                            <Input
                                                id="api_key"
                                                type="password"
                                                value={apiKeyForm.data.api_key}
                                                onChange={(e) =>
                                                    apiKeyForm.setData(
                                                        'api_key',
                                                        e.target.value,
                                                    )
                                                }
                                                placeholder="sk-..."
                                                required
                                            />
                                            <InputError
                                                message={
                                                    apiKeyForm.errors.api_key
                                                }
                                            />
                                        </div>

                                        <DialogFooter className="gap-2">
                                            <DialogClose asChild>
                                                <Button variant="secondary">
                                                    Cancel
                                                </Button>
                                            </DialogClose>
                                            <Button
                                                disabled={apiKeyForm.processing}
                                            >
                                                Save Key
                                            </Button>
                                        </DialogFooter>
                                    </form>
                                </DialogContent>
                            </Dialog>
                        </CardHeader>

                        <CardContent>
                            {apiKeys.length === 0 ? (
                                <p className="py-4 text-center text-sm text-muted-foreground">
                                    No API keys configured yet. Your agents use
                                    managed credits from your subscription.
                                </p>
                            ) : (
                                <div className="divide-y">
                                    {apiKeys.map((apiKey) => (
                                        <div
                                            key={apiKey.id}
                                            className="flex items-center justify-between py-3 first:pt-0 last:pb-0"
                                        >
                                            <div className="min-w-0">
                                                <p className="text-sm font-medium">
                                                    {providerLabels[
                                                        apiKey.provider
                                                    ] ?? apiKey.provider}
                                                </p>
                                                <p className="truncate font-mono text-xs text-muted-foreground">
                                                    {apiKey.masked_key}
                                                </p>
                                            </div>

                                            <div className="flex shrink-0 items-center gap-2">
                                                <button
                                                    type="button"
                                                    className="cursor-pointer"
                                                    onClick={() => {
                                                        router.patch(
                                                            `/api-keys/${apiKey.id}`,
                                                            {
                                                                is_active:
                                                                    !apiKey.is_active,
                                                            },
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        );
                                                    }}
                                                >
                                                    {apiKey.is_active ? (
                                                        <Badge variant="default">
                                                            Active
                                                        </Badge>
                                                    ) : (
                                                        <Badge variant="secondary">
                                                            Inactive
                                                        </Badge>
                                                    )}
                                                </button>

                                                <DeleteConfirmation
                                                    title="Delete API key?"
                                                    description={`This will remove the ${providerLabels[apiKey.provider] ?? apiKey.provider} API key. Agents using this provider will stop working.`}
                                                    onConfirm={() => {
                                                        router.delete(
                                                            `/api-keys/${apiKey.id}`,
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        );
                                                    }}
                                                />
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Environment Variables */}
                    <Card>
                        <CardHeader className="flex-row items-center justify-between">
                            <div className="flex flex-col gap-1.5">
                                <CardTitle>Environment Variables</CardTitle>
                                <CardDescription>
                                    Custom variables synced to your server's
                                    environment.
                                </CardDescription>
                            </div>

                            <Dialog
                                open={addEnvVarOpen}
                                onOpenChange={setAddEnvVarOpen}
                            >
                                <DialogTrigger asChild>
                                    <Button size="sm">Add Variable</Button>
                                </DialogTrigger>
                                <DialogContent>
                                    <DialogTitle>
                                        Add environment variable
                                    </DialogTitle>
                                    <DialogDescription>
                                        Add a new variable to your server's
                                        environment. Keys must be uppercase with
                                        underscores.
                                    </DialogDescription>

                                    <form
                                        onSubmit={submitEnvVar}
                                        className="space-y-4"
                                    >
                                        <div className="grid gap-2">
                                            <Label htmlFor="env_key">Key</Label>
                                            <Input
                                                id="env_key"
                                                value={envVarForm.data.key}
                                                onChange={(e) =>
                                                    envVarForm.setData(
                                                        'key',
                                                        e.target.value,
                                                    )
                                                }
                                                placeholder="MY_VARIABLE"
                                                className="font-mono"
                                                required
                                            />
                                            <InputError
                                                message={envVarForm.errors.key}
                                            />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="env_value">
                                                Value
                                            </Label>
                                            <Input
                                                id="env_value"
                                                value={envVarForm.data.value}
                                                onChange={(e) =>
                                                    envVarForm.setData(
                                                        'value',
                                                        e.target.value,
                                                    )
                                                }
                                                placeholder="value"
                                                required
                                            />
                                            <InputError
                                                message={
                                                    envVarForm.errors.value
                                                }
                                            />
                                        </div>

                                        <div className="flex items-center gap-2">
                                            <Checkbox
                                                id="is_secret"
                                                checked={
                                                    envVarForm.data.is_secret
                                                }
                                                onCheckedChange={(checked) =>
                                                    envVarForm.setData(
                                                        'is_secret',
                                                        checked === true,
                                                    )
                                                }
                                            />
                                            <Label
                                                htmlFor="is_secret"
                                                className="cursor-pointer"
                                            >
                                                Mark as secret (value will be
                                                masked)
                                            </Label>
                                        </div>

                                        <DialogFooter className="gap-2">
                                            <DialogClose asChild>
                                                <Button variant="secondary">
                                                    Cancel
                                                </Button>
                                            </DialogClose>
                                            <Button
                                                disabled={envVarForm.processing}
                                            >
                                                Add Variable
                                            </Button>
                                        </DialogFooter>
                                    </form>
                                </DialogContent>
                            </Dialog>
                        </CardHeader>

                        <CardContent>
                            {envVars.length === 0 ? (
                                <p className="py-4 text-center text-sm text-muted-foreground">
                                    No environment variables configured yet.
                                </p>
                            ) : (
                                <div className="divide-y">
                                    {envVars.map((envVar) => (
                                        <div
                                            key={envVar.id}
                                            className="flex items-center justify-between py-3 first:pt-0 last:pb-0"
                                        >
                                            <div className="min-w-0">
                                                <p className="font-mono text-sm font-medium">
                                                    {envVar.key}
                                                </p>
                                                <p className="truncate font-mono text-xs text-muted-foreground">
                                                    {envVar.value_preview}
                                                </p>
                                            </div>

                                            <div className="flex shrink-0 items-center gap-2">
                                                {envVar.is_system && (
                                                    <Badge variant="outline">
                                                        System
                                                    </Badge>
                                                )}
                                                {envVar.is_secret && (
                                                    <Badge variant="secondary">
                                                        Secret
                                                    </Badge>
                                                )}

                                                {!envVar.is_system && (
                                                    <DeleteConfirmation
                                                        title="Delete environment variable?"
                                                        description={`This will remove ${envVar.key} from your server's environment.`}
                                                        onConfirm={() => {
                                                            router.delete(
                                                                `/api-keys/env-vars/${envVar.id}`,
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            );
                                                        }}
                                                    />
                                                )}
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}

function DeleteConfirmation({
    title,
    description,
    onConfirm,
}: {
    title: string;
    description: string;
    onConfirm: () => void;
}) {
    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button variant="ghost" size="sm">
                    Delete
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>{title}</DialogTitle>
                <DialogDescription>{description}</DialogDescription>

                <DialogFooter className="gap-2">
                    <DialogClose asChild>
                        <Button variant="secondary">Cancel</Button>
                    </DialogClose>
                    <Button variant="destructive" onClick={onConfirm}>
                        Delete
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
