import { Transition } from '@headlessui/react';
import { Form, Head, router } from '@inertiajs/react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
import { Separator } from '@/components/ui/separator';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import type { BreadcrumbItem, Team } from '@/types';

type ApiKeyItem = {
    id: number;
    provider: string;
    masked_key: string;
    is_active: boolean;
    created_at: string;
    updated_at: string;
};

const providerLabels: Record<string, string> = {
    anthropic: 'Anthropic',
    openai: 'OpenAI',
    open_router: 'OpenRouter',
};

export default function ApiKeysIndex({
    team,
    apiKeys,
}: {
    team: Team;
    apiKeys: ApiKeyItem[];
}) {
    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'API Keys',
            href: `/settings/teams/${team.id}/api-keys`,
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="API Keys" />

            <h1 className="sr-only">API Keys</h1>

            <SettingsLayout>
                {/* Existing Keys */}
                <div className="space-y-6">
                    <Heading
                        variant="small"
                        title="API Keys"
                        description="Manage LLM provider API keys for your team."
                    />

                    {apiKeys.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No API keys configured yet. Add one below to get
                            started.
                        </p>
                    ) : (
                        <div className="space-y-4">
                            {apiKeys.map((apiKey) => (
                                <div
                                    key={apiKey.id}
                                    className="flex items-center justify-between"
                                >
                                    <div>
                                        <p className="text-sm font-medium">
                                            {providerLabels[apiKey.provider] ??
                                                apiKey.provider}
                                        </p>
                                        <p className="text-sm text-muted-foreground">
                                            {apiKey.masked_key}
                                        </p>
                                    </div>

                                    <div className="flex items-center gap-2">
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => {
                                                router.patch(
                                                    `/settings/teams/${team.id}/api-keys/${apiKey.id}`,
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
                                        </Button>

                                        <Dialog>
                                            <DialogTrigger asChild>
                                                <Button
                                                    variant="destructive"
                                                    size="sm"
                                                >
                                                    Delete
                                                </Button>
                                            </DialogTrigger>
                                            <DialogContent>
                                                <DialogTitle>
                                                    Delete API key?
                                                </DialogTitle>
                                                <DialogDescription>
                                                    This will remove the{' '}
                                                    {providerLabels[
                                                        apiKey.provider
                                                    ] ?? apiKey.provider}{' '}
                                                    API key. Agents using this
                                                    provider will stop working.
                                                </DialogDescription>

                                                <DialogFooter className="gap-2">
                                                    <DialogClose asChild>
                                                        <Button variant="secondary">
                                                            Cancel
                                                        </Button>
                                                    </DialogClose>

                                                    <Button
                                                        variant="destructive"
                                                        onClick={() => {
                                                            router.delete(
                                                                `/settings/teams/${team.id}/api-keys/${apiKey.id}`,
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            );
                                                        }}
                                                    >
                                                        Delete
                                                    </Button>
                                                </DialogFooter>
                                            </DialogContent>
                                        </Dialog>
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </div>

                <Separator />

                {/* Add Key */}
                <div className="space-y-6">
                    <Heading
                        variant="small"
                        title="Add API key"
                        description="Add or update an API key for a provider."
                    />

                    <Form
                        action={`/settings/teams/${team.id}/api-keys`}
                        method="post"
                        options={{
                            preserveScroll: true,
                        }}
                        resetOnSuccess
                        className="space-y-6"
                    >
                        {({ processing, recentlySuccessful, errors }) => (
                            <>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="grid gap-2">
                                        <Label htmlFor="provider">
                                            Provider
                                        </Label>

                                        <select
                                            id="provider"
                                            name="provider"
                                            defaultValue="anthropic"
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
                                            className="mt-2"
                                            message={errors.provider}
                                        />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="api_key">API Key</Label>

                                        <Input
                                            id="api_key"
                                            name="api_key"
                                            type="password"
                                            required
                                            placeholder="sk-..."
                                        />

                                        <InputError
                                            className="mt-2"
                                            message={errors.api_key}
                                        />
                                    </div>
                                </div>

                                <div className="flex items-center gap-4">
                                    <Button disabled={processing}>
                                        Save Key
                                    </Button>

                                    <Transition
                                        show={recentlySuccessful}
                                        enter="transition ease-in-out"
                                        enterFrom="opacity-0"
                                        leave="transition ease-in-out"
                                        leaveTo="opacity-0"
                                    >
                                        <p className="text-sm text-neutral-600">
                                            Saved
                                        </p>
                                    </Transition>
                                </div>
                            </>
                        )}
                    </Form>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
