import { Transition } from '@headlessui/react';
import { Form, Head, usePoll } from '@inertiajs/react';
import { Loader2 } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import type { Agent, BreadcrumbItem } from '@/types';

type AvailableModel = {
    value: string;
    label: string;
    provider: string;
};

type ModelTierOption = {
    value: string;
    label: string;
    description: string;
    cost: string;
    primaryModel: string;
};

// Detect which tier an agent is on based on its primary model
function detectTier(
    modelPrimary: string | null,
    tiers: ModelTierOption[],
): string | null {
    if (!modelPrimary) return null;
    const match = tiers.find((t) => t.primaryModel === modelPrimary);
    return match?.value ?? null;
}

export default function ConfigureAgent({
    agent,
    availableModels,
    modelTiers = [],
}: {
    agent: Agent;
    availableModels: AvailableModel[];
    modelTiers?: ModelTierOption[];
}) {
    const [configOpen, setConfigOpen] = useState(false);
    const currentTier = detectTier(agent.model_primary, modelTiers);
    const [showAdvancedModel, setShowAdvancedModel] = useState(!currentTier);

    usePoll(2000, { only: ['agent'] }, { autoStart: agent.is_syncing });

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Agents', href: '/agents' },
        { title: agent.name, href: `/agents/${agent.id}` },
        { title: 'Configure', href: `/agents/${agent.id}/configure` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Configure ${agent.name}`} />

            <div className="px-4 py-6 sm:px-6">
                <div className="mx-auto max-w-3xl space-y-6">
                    {agent.is_syncing && (
                        <div className="flex items-center gap-3 rounded-lg border border-blue-200 bg-blue-50 p-4 dark:border-blue-900 dark:bg-blue-950/50">
                            <Loader2 className="size-4 shrink-0 animate-spin text-blue-600 dark:text-blue-400" />
                            <p className="text-sm text-blue-700 dark:text-blue-300">
                                Applying changes to server...
                            </p>
                        </div>
                    )}

                    <Card>
                        <CardHeader>
                            <CardTitle>Configuration</CardTitle>
                            <CardDescription>
                                Update the agent's prompts, identity, and model
                                settings.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <Form
                                action={`/agents/${agent.id}`}
                                method="patch"
                                options={{
                                    preserveScroll: true,
                                }}
                                className="space-y-4"
                            >
                                {({
                                    processing,
                                    recentlySuccessful,
                                    errors,
                                }) => (
                                    <>
                                        <div className="grid gap-2">
                                            <Label htmlFor="name">
                                                Agent name
                                            </Label>
                                            <Input
                                                id="name"
                                                className="w-full"
                                                defaultValue={agent.name}
                                                name="name"
                                                required
                                                placeholder="Agent name"
                                            />
                                            <InputError message={errors.name} />
                                        </div>

                                        {/* Model tier selector */}
                                        <div className="grid gap-2">
                                            <Label>Intelligence tier</Label>

                                            {!showAdvancedModel ? (
                                                <>
                                                    <div className="grid grid-cols-2 gap-3">
                                                        {modelTiers.map(
                                                            (tier) => (
                                                                <label
                                                                    key={
                                                                        tier.value
                                                                    }
                                                                    className={cn(
                                                                        'cursor-pointer rounded-xl border px-4 py-4 text-left transition-all',
                                                                        currentTier ===
                                                                            tier.value &&
                                                                            !showAdvancedModel
                                                                            ? 'border-foreground bg-accent shadow-sm'
                                                                            : 'border-border hover:border-foreground/30',
                                                                    )}
                                                                >
                                                                    <input
                                                                        type="radio"
                                                                        name="model_primary"
                                                                        value={
                                                                            tier.primaryModel
                                                                        }
                                                                        defaultChecked={
                                                                            currentTier ===
                                                                            tier.value
                                                                        }
                                                                        className="sr-only"
                                                                        onChange={() => {}}
                                                                    />
                                                                    <div className="mb-1 text-lg">
                                                                        {tier.value ===
                                                                        'efficient'
                                                                            ? '⚡'
                                                                            : '🧠'}
                                                                    </div>
                                                                    <p className="text-sm font-bold">
                                                                        {
                                                                            tier.label
                                                                        }
                                                                    </p>
                                                                    <p className="mt-0.5 text-xs text-muted-foreground">
                                                                        {
                                                                            tier.description
                                                                        }
                                                                    </p>
                                                                    <p className="mt-2 text-[11px] font-medium text-muted-foreground">
                                                                        {
                                                                            tier.cost
                                                                        }{' '}
                                                                        in AI
                                                                        costs
                                                                    </p>
                                                                </label>
                                                            ),
                                                        )}
                                                    </div>

                                                    {availableModels.length >
                                                        0 && (
                                                        <button
                                                            type="button"
                                                            onClick={() =>
                                                                setShowAdvancedModel(
                                                                    true,
                                                                )
                                                            }
                                                            className="mt-1 text-xs text-muted-foreground underline transition-colors hover:text-foreground"
                                                        >
                                                            Advanced: choose a
                                                            specific model
                                                        </button>
                                                    )}
                                                </>
                                            ) : (
                                                <>
                                                    <select
                                                        id="model_primary"
                                                        name="model_primary"
                                                        defaultValue={
                                                            agent.model_primary ??
                                                            ''
                                                        }
                                                        className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs transition-[color,box-shadow] outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                                    >
                                                        <option value="">
                                                            Select a model...
                                                        </option>
                                                        {availableModels.map(
                                                            (model) => (
                                                                <option
                                                                    key={
                                                                        model.value
                                                                    }
                                                                    value={
                                                                        model.value
                                                                    }
                                                                >
                                                                    {
                                                                        model.label
                                                                    }{' '}
                                                                    (
                                                                    {
                                                                        model.provider
                                                                    }
                                                                    )
                                                                </option>
                                                            ),
                                                        )}
                                                    </select>

                                                    <button
                                                        type="button"
                                                        onClick={() =>
                                                            setShowAdvancedModel(
                                                                false,
                                                            )
                                                        }
                                                        className="mt-1 text-xs text-muted-foreground underline transition-colors hover:text-foreground"
                                                    >
                                                        Back to simple selection
                                                    </button>
                                                </>
                                            )}

                                            <InputError
                                                message={errors.model_primary}
                                            />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="system_prompt">
                                                System prompt (AGENTS.md)
                                            </Label>
                                            <textarea
                                                id="system_prompt"
                                                name="system_prompt"
                                                rows={3}
                                                defaultValue={
                                                    agent.system_prompt ?? ''
                                                }
                                                placeholder="Describe what this agent should do..."
                                                className="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs transition-[color,box-shadow] outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                            />
                                            <InputError
                                                message={errors.system_prompt}
                                            />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="identity">
                                                Identity (IDENTITY.md)
                                            </Label>
                                            <textarea
                                                id="identity"
                                                name="identity"
                                                rows={2}
                                                defaultValue={
                                                    agent.identity ?? ''
                                                }
                                                placeholder="Define the agent's identity..."
                                                className="w-full rounded-md border border-input bg-transparent px-3 py-2 font-mono text-sm shadow-xs transition-[color,box-shadow] outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                            />
                                            <InputError
                                                message={errors.identity}
                                            />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="soul">
                                                Soul (SOUL.md)
                                            </Label>
                                            <textarea
                                                id="soul"
                                                name="soul"
                                                rows={3}
                                                defaultValue={agent.soul ?? ''}
                                                placeholder="Personality, tone, and behavioral guidelines..."
                                                className="w-full rounded-md border border-input bg-transparent px-3 py-2 font-mono text-sm shadow-xs transition-[color,box-shadow] outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                            />
                                            <InputError
                                                message={
                                                    (
                                                        errors as Record<
                                                            string,
                                                            string
                                                        >
                                                    ).soul
                                                }
                                            />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="tools_config">
                                                Tools (TOOLS.md)
                                            </Label>
                                            <textarea
                                                id="tools_config"
                                                name="tools_config"
                                                rows={3}
                                                defaultValue={
                                                    agent.tools_config ?? ''
                                                }
                                                placeholder="Tool usage patterns and environment notes..."
                                                className="w-full rounded-md border border-input bg-transparent px-3 py-2 font-mono text-sm shadow-xs transition-[color,box-shadow] outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                            />
                                            <InputError
                                                message={
                                                    (
                                                        errors as Record<
                                                            string,
                                                            string
                                                        >
                                                    ).tools_config
                                                }
                                            />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="user_context">
                                                User context (USER.md)
                                            </Label>
                                            <textarea
                                                id="user_context"
                                                name="user_context"
                                                rows={3}
                                                defaultValue={
                                                    agent.user_context ?? ''
                                                }
                                                placeholder="Company info, preferences, and custom context..."
                                                className="w-full rounded-md border border-input bg-transparent px-3 py-2 font-mono text-sm shadow-xs transition-[color,box-shadow] outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                            />
                                            <InputError
                                                message={
                                                    (
                                                        errors as Record<
                                                            string,
                                                            string
                                                        >
                                                    ).user_context
                                                }
                                            />
                                        </div>

                                        <div className="flex items-center gap-4">
                                            <Button disabled={processing}>
                                                {processing
                                                    ? 'Saving...'
                                                    : 'Save'}
                                            </Button>
                                            <Transition
                                                show={recentlySuccessful}
                                                enter="transition ease-in-out"
                                                enterFrom="opacity-0"
                                                leave="transition ease-in-out"
                                                leaveTo="opacity-0"
                                            >
                                                <p className="text-sm text-neutral-600">
                                                    {agent.server_id
                                                        ? 'Saved — deploying to server...'
                                                        : 'Saved'}
                                                </p>
                                            </Transition>
                                        </div>
                                    </>
                                )}
                            </Form>
                        </CardContent>
                    </Card>

                    {/* Configuration snapshot */}
                    <Collapsible open={configOpen} onOpenChange={setConfigOpen}>
                        <Card>
                            <CardHeader className="flex-row items-center justify-between">
                                <div className="flex flex-col gap-1.5">
                                    <CardTitle>
                                        Configuration snapshot
                                    </CardTitle>
                                    <CardDescription>
                                        Raw agent configuration data.
                                    </CardDescription>
                                </div>
                                <CollapsibleTrigger asChild>
                                    <Button variant="ghost" size="sm">
                                        {configOpen ? 'Hide' : 'Show'}
                                    </Button>
                                </CollapsibleTrigger>
                            </CardHeader>
                            <CollapsibleContent>
                                <CardContent>
                                    <pre className="max-h-64 overflow-auto rounded-md bg-muted p-4 text-xs">
                                        {JSON.stringify(agent, null, 2)}
                                    </pre>
                                </CardContent>
                            </CollapsibleContent>
                        </Card>
                    </Collapsible>
                </div>
            </div>
        </AppLayout>
    );
}
