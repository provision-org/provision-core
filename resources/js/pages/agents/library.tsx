import { Head, Link, router } from '@inertiajs/react';
import {
    ChevronDown,
    ChevronRight,
    Loader2,
    Plus,
    Users,
    X,
} from 'lucide-react';
import { useCallback, useState } from 'react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { roleLabels } from '@/lib/agents';
import type { AgentTemplate, BreadcrumbItem, TeamPack } from '@/types';

const ROLE_COLORS: Record<string, string> = {
    bdr: '#6366f1',
    executive_assistant: '#8b5cf6',
    frontend_developer: '#06b6d4',
    backend_developer: '#0ea5e9',
    researcher: '#f59e0b',
    content_writer: '#ec4899',
    customer_support: '#14b8a6',
    data_analyst: '#f97316',
    project_manager: '#22c55e',
    design_reviewer: '#a855f7',
    custom: '#64748b',
};

function TemplateCard({
    template,
    onSelect,
}: {
    template: AgentTemplate;
    onSelect: (template: AgentTemplate) => void;
}) {
    const color = ROLE_COLORS[template.role] ?? '#64748b';

    return (
        <button
            type="button"
            onClick={() => onSelect(template)}
            className="group flex cursor-pointer flex-col items-center text-left transition-transform duration-500 hover:rotate-[1.5deg]"
            style={{ transformOrigin: 'top center' }}
        >
            {/* Lanyard strap */}
            <div className="h-8 w-[14px] rounded-[2px] bg-gradient-to-b from-muted/60 via-muted to-muted-foreground/15" />
            {/* Metal clip */}
            <div className="-mt-0.5 mb-[-2px] h-[14px] w-[18px] rounded-b-[5px] border-2 border-t-0 border-muted-foreground/20 bg-gradient-to-b from-muted/80 to-muted/30" />

            {/* Badge card */}
            <div className="w-full overflow-hidden rounded-xl shadow-sm transition-all duration-500 group-hover:shadow-md dark:shadow-none dark:ring-1 dark:ring-border dark:group-hover:ring-border/80">
                {/* Header */}
                <div className="bg-muted/50 px-4 pt-5 pb-4 text-center dark:bg-muted/30">
                    {template.avatar_path ? (
                        <img
                            src={`/storage/${template.avatar_path}`}
                            alt={template.name}
                            className="mx-auto mb-3 size-14 rounded-full object-cover ring-2 ring-background"
                        />
                    ) : (
                        <div
                            className="mx-auto mb-3 flex size-14 items-center justify-center rounded-full text-lg font-bold text-white ring-2 ring-background"
                            style={{ backgroundColor: color }}
                        >
                            {template.emoji}
                        </div>
                    )}
                    <h3 className="text-sm font-bold text-foreground">
                        {template.name}
                    </h3>
                    <p className="mt-0.5 text-[11px] font-medium text-muted-foreground">
                        {roleLabels[template.role] ?? template.role}
                    </p>
                </div>

                {/* Body */}
                <div className="border-t bg-card px-4 py-4 text-center">
                    <p className="text-[12px] leading-[1.6] text-muted-foreground">
                        {template.tagline}
                    </p>
                </div>
            </div>
        </button>
    );
}

function PackCard({
    pack,
    onSelect,
}: {
    pack: TeamPack;
    onSelect: (pack: TeamPack) => void;
}) {
    const members = pack.templates ?? [];

    return (
        <button
            type="button"
            onClick={() => onSelect(pack)}
            className="group flex cursor-pointer flex-col items-center text-left transition-transform duration-500 hover:rotate-[1deg]"
            style={{ transformOrigin: 'top center' }}
        >
            {/* Lanyard strap */}
            <div className="h-8 w-[14px] rounded-[2px] bg-gradient-to-b from-muted/60 via-muted to-muted-foreground/15" />
            {/* Metal clip */}
            <div className="-mt-0.5 mb-[-2px] h-[14px] w-[18px] rounded-b-[5px] border-2 border-t-0 border-muted-foreground/20 bg-gradient-to-b from-muted/80 to-muted/30" />

            {/* Badge card */}
            <div className="w-full overflow-hidden rounded-xl shadow-sm transition-all duration-500 group-hover:shadow-md dark:shadow-none dark:ring-1 dark:ring-border dark:group-hover:ring-border/80">
                {/* Header — team member avatars */}
                <div className="bg-muted/50 px-5 pt-5 pb-4 dark:bg-muted/30">
                    {/* Stacked avatars */}
                    <div className="flex justify-center -space-x-2">
                        {members.map((t) => {
                            const color = ROLE_COLORS[t.role] ?? '#64748b';
                            return t.avatar_path ? (
                                <img
                                    key={t.id}
                                    src={`/storage/${t.avatar_path}`}
                                    alt={t.name}
                                    className="size-11 rounded-full object-cover ring-2 ring-background"
                                />
                            ) : (
                                <div
                                    key={t.id}
                                    className="flex size-11 items-center justify-center rounded-full text-sm ring-2 ring-background"
                                    style={{ backgroundColor: color }}
                                >
                                    <span className="text-base">{t.emoji}</span>
                                </div>
                            );
                        })}
                    </div>

                    {/* Pack name */}
                    <h3 className="mt-3 text-center text-sm font-bold text-foreground">
                        {pack.name}
                    </h3>
                    <p className="mt-0.5 text-center text-[11px] font-medium text-muted-foreground">
                        {members.length} agents
                    </p>
                </div>

                {/* Body — tagline + member names */}
                <div className="border-t bg-card px-5 py-4">
                    <p className="text-center text-[12px] leading-[1.6] text-muted-foreground">
                        {pack.tagline}
                    </p>

                    <div className="mt-3 flex flex-wrap justify-center gap-1">
                        {members.map((t) => (
                            <span
                                key={t.id}
                                className="rounded-full bg-muted/70 px-2 py-0.5 text-[10px] font-medium text-muted-foreground"
                            >
                                {t.name}
                            </span>
                        ))}
                    </div>
                </div>
            </div>
        </button>
    );
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Agent Library', href: '/agents/library' },
];

export default function AgentLibrary({
    templates,
    packs = [],
    canCreateAgent = false,
    currentPlan,
    seatPriceCents = 4900,
    planPriceCents = 4900,
    currentAgentCount = 0,
    includedAgents = 1,
    extraSeats = 0,
    isOnTrial = false,
    trialEndsAt,
}: {
    templates: AgentTemplate[];
    packs?: TeamPack[];
    canCreateAgent?: boolean;
    currentPlan?: string;
    seatPriceCents?: number;
    planPriceCents?: number;
    currentAgentCount?: number;
    includedAgents?: number;
    extraSeats?: number;
    isOnTrial?: boolean;
    trialEndsAt?: string;
}) {
    const [selectedTemplate, setSelectedTemplate] =
        useState<AgentTemplate | null>(null);
    const [selectedPack, setSelectedPack] = useState<TeamPack | null>(null);
    const [hiring, setHiring] = useState(false);
    const [dialogStep, setDialogStep] = useState<1 | 2>(1);
    const [templateDetails, setTemplateDetails] = useState<{
        capabilities: string[];
        recommended_tools: Array<{ name: string; url?: string }>;
    } | null>(null);
    const [loadingDetails, setLoadingDetails] = useState(false);

    // Single agent tools
    const [hireTools, setHireTools] = useState<
        Array<{ name: string; url: string }>
    >([]);
    const [toolName, setToolName] = useState('');
    const [toolUrl, setToolUrl] = useState('');

    // Pack tools — per-agent
    const [packTools, setPackTools] = useState<
        Record<string, Array<{ name: string; url: string }>>
    >({});
    const [packToolInputs, setPackToolInputs] = useState<
        Record<string, { name: string; url: string }>
    >({});
    const [expandedAgent, setExpandedAgent] = useState<string | null>(null);
    const [packDetailsLoaded, setPackDetailsLoaded] = useState(false);

    // Fetch template details when a template is selected
    const selectTemplate = useCallback((template: AgentTemplate) => {
        setSelectedTemplate(template);
        setTemplateDetails(null);
        setLoadingDetails(true);
        setHireTools([]);
        setDialogStep(1);

        fetch(`/agents/library/${template.slug}/details`, {
            headers: { Accept: 'application/json' },
        })
            .then((res) => res.json())
            .then((data) => {
                setTemplateDetails(data);
                setHireTools(
                    (data.recommended_tools ?? []).map(
                        (t: { name: string; url?: string }) => ({
                            name: t.name,
                            url: t.url ?? '',
                        }),
                    ),
                );
            })
            .catch(() =>
                setTemplateDetails({ capabilities: [], recommended_tools: [] }),
            )
            .finally(() => setLoadingDetails(false));
    }, []);

    // Fetch details for all agents in a pack
    const selectPack = useCallback((pack: TeamPack) => {
        setSelectedPack(pack);
        setDialogStep(1);
        setPackTools({});
        setPackToolInputs({});
        setExpandedAgent(null);
        setPackDetailsLoaded(false);

        // Fetch details for each template in the pack
        const members = pack.templates ?? [];
        if (members.length === 0) return;

        Promise.all(
            members.map((t) =>
                fetch(`/agents/library/${t.slug}/details`, {
                    headers: { Accept: 'application/json' },
                })
                    .then((res) => res.json())
                    .then((data) => ({
                        slug: t.slug,
                        tools: data.recommended_tools ?? [],
                    }))
                    .catch(() => ({ slug: t.slug, tools: [] })),
            ),
        ).then((results) => {
            const toolsMap: Record<
                string,
                Array<{ name: string; url: string }>
            > = {};
            for (const r of results) {
                toolsMap[r.slug] = r.tools.map(
                    (t: { name: string; url?: string }) => ({
                        name: t.name,
                        url: t.url ?? '',
                    }),
                );
            }
            setPackTools(toolsMap);
            setPackDetailsLoaded(true);
            setExpandedAgent(members[0]?.slug ?? null);
        });
    }, []);

    function addTool() {
        if (!toolName.trim()) return;
        setHireTools([
            ...hireTools,
            { name: toolName.trim(), url: toolUrl.trim() },
        ]);
        setToolName('');
        setToolUrl('');
    }

    function addPackTool(slug: string) {
        const input = packToolInputs[slug];
        if (!input?.name?.trim()) return;
        setPackTools({
            ...packTools,
            [slug]: [
                ...(packTools[slug] ?? []),
                { name: input.name.trim(), url: (input.url ?? '').trim() },
            ],
        });
        setPackToolInputs({ ...packToolInputs, [slug]: { name: '', url: '' } });
    }

    function handleHire() {
        if (!selectedTemplate) return;

        setHiring(true);
        router.post(
            `/agents/hire/${selectedTemplate.slug}`,
            { tools: hireTools },
            {
                onFinish: () => setHiring(false),
            },
        );
    }

    function handlePackHire() {
        if (!selectedPack) return;

        setHiring(true);
        router.post(
            `/agents/packs/${selectedPack.slug}/hire`,
            { agent_tools: packTools },
            {
                onFinish: () => {
                    setHiring(false);
                    setSelectedPack(null);
                },
            },
        );
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Agent Library" />

            <div className="px-4 py-6 sm:px-6">
                <div className="flex items-center justify-between gap-4">
                    <Heading
                        variant="small"
                        title="Agent Library"
                        description="Browse ready-made agents and team packs you can hire instantly."
                    />

                    <Button asChild size="sm" variant="outline">
                        <Link href="/agents/create">
                            <Plus className="mr-1.5 size-3.5" />
                            Create Your Own
                        </Link>
                    </Button>
                </div>

                {!currentPlan && (
                    <div className="mt-4 overflow-hidden rounded-xl border border-primary/20 bg-gradient-to-r from-primary/[0.06] via-primary/[0.03] to-transparent dark:from-primary/[0.10] dark:via-primary/[0.04]">
                        <div className="flex items-center justify-between px-6 py-5">
                            <div className="flex items-center gap-4">
                                <div className="flex size-10 items-center justify-center rounded-full bg-primary/10">
                                    <Users className="size-5 text-primary" />
                                </div>
                                <div>
                                    <h3 className="text-sm font-semibold text-foreground">
                                        Ready to deploy your first AI employee?
                                    </h3>
                                    <p className="mt-0.5 text-sm text-muted-foreground">
                                        Subscribe to get a dedicated server,
                                        email inbox, browser, and channel access
                                        for your agents.
                                    </p>
                                </div>
                            </div>
                            <Button asChild size="sm" className="shrink-0">
                                <Link href="/subscribe">Get started</Link>
                            </Button>
                        </div>
                    </div>
                )}

                {/* Team Packs */}
                {packs.length > 0 && (
                    <div className="mt-10">
                        <div className="flex items-center gap-2">
                            <Users className="size-4 text-primary/60" />
                            <h3 className="text-sm font-semibold text-foreground">
                                Team Packs
                            </h3>
                        </div>
                        <p className="mt-1 text-[13px] text-muted-foreground">
                            Hire a ready-made team in one click — skip the
                            setup.
                        </p>
                        <div className="mt-6 grid grid-cols-2 gap-x-5 gap-y-10 sm:grid-cols-3 lg:grid-cols-4">
                            {packs.map((pack) => (
                                <PackCard
                                    key={pack.id}
                                    pack={pack}
                                    onSelect={selectPack}
                                />
                            ))}
                        </div>
                    </div>
                )}

                {/* Individual Agents */}
                {templates.length > 0 && (
                    <div className="mt-14">
                        <h3 className="text-sm font-semibold text-foreground">
                            Individual Agents
                        </h3>
                        <p className="mt-1 text-[13px] text-muted-foreground">
                            Hire a specialist for a specific role.
                        </p>
                        <div className="mt-6 grid grid-cols-2 gap-x-5 gap-y-10 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
                            {templates.map((template) => (
                                <TemplateCard
                                    key={template.id}
                                    template={template}
                                    onSelect={selectTemplate}
                                />
                            ))}
                        </div>
                    </div>
                )}
            </div>

            {/* Template hire dialog — 2 steps */}
            <Dialog
                open={selectedTemplate !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setSelectedTemplate(null);
                        setTemplateDetails(null);
                        setHireTools([]);
                        setDialogStep(1);
                    }
                }}
            >
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <div className="flex items-center gap-3">
                            {selectedTemplate?.avatar_path ? (
                                <img
                                    src={`/storage/${selectedTemplate.avatar_path}`}
                                    alt={selectedTemplate.name}
                                    className="size-10 rounded-full object-cover"
                                />
                            ) : (
                                <div
                                    className="flex size-10 items-center justify-center rounded-full text-lg"
                                    style={{
                                        backgroundColor:
                                            ROLE_COLORS[
                                                selectedTemplate?.role ?? ''
                                            ] ?? '#64748b',
                                    }}
                                >
                                    {selectedTemplate?.emoji}
                                </div>
                            )}
                            <div>
                                <DialogTitle className="text-left">
                                    {selectedTemplate?.name}
                                </DialogTitle>
                                <DialogDescription className="text-left">
                                    {roleLabels[selectedTemplate?.role ?? ''] ??
                                        selectedTemplate?.role}{' '}
                                    · {selectedTemplate?.tagline}
                                </DialogDescription>
                            </div>
                        </div>
                    </DialogHeader>

                    {/* Step indicator */}
                    <div className="flex items-center gap-2">
                        <div
                            className={`h-1 flex-1 rounded-full ${dialogStep >= 1 ? 'bg-foreground' : 'bg-muted'}`}
                        />
                        <div
                            className={`h-1 flex-1 rounded-full ${dialogStep >= 2 ? 'bg-foreground' : 'bg-muted'}`}
                        />
                    </div>

                    {dialogStep === 1 && (
                        <>
                            {/* Capabilities */}
                            {loadingDetails ? (
                                <div className="flex items-center justify-center py-6">
                                    <Loader2 className="size-5 animate-spin text-muted-foreground" />
                                </div>
                            ) : templateDetails?.capabilities &&
                              templateDetails.capabilities.length > 0 ? (
                                <div>
                                    <p className="mb-2 text-xs font-medium tracking-wider text-muted-foreground uppercase">
                                        What they do
                                    </p>
                                    <ul className="space-y-1.5">
                                        {templateDetails.capabilities.map(
                                            (cap, i) => (
                                                <li
                                                    key={i}
                                                    className="flex items-start gap-2 text-sm text-muted-foreground"
                                                >
                                                    <span className="mt-1.5 size-1 shrink-0 rounded-full bg-primary" />
                                                    {cap}
                                                </li>
                                            ),
                                        )}
                                    </ul>
                                </div>
                            ) : null}

                            {/* Pricing */}
                            {!canCreateAgent && (
                                <div className="rounded-lg border border-border bg-muted/50 p-3 text-sm">
                                    {isOnTrial ? (
                                        <>
                                            <p className="text-foreground">
                                                This adds a{' '}
                                                <span className="font-semibold">
                                                    $
                                                    {(
                                                        seatPriceCents / 100
                                                    ).toFixed(0)}
                                                    /mo
                                                </span>{' '}
                                                agent seat. Your trial continues
                                                — no charge until{' '}
                                                <span className="font-semibold">
                                                    {trialEndsAt
                                                        ? new Date(
                                                              trialEndsAt,
                                                          ).toLocaleDateString(
                                                              'en-US',
                                                              {
                                                                  month: 'short',
                                                                  day: 'numeric',
                                                              },
                                                          )
                                                        : 'trial ends'}
                                                </span>
                                                .
                                            </p>
                                            <p className="mt-1.5 text-xs text-muted-foreground">
                                                After trial: $
                                                {(planPriceCents / 100).toFixed(
                                                    0,
                                                )}
                                                /mo plan + {extraSeats + 1} seat
                                                {extraSeats + 1 !== 1
                                                    ? 's'
                                                    : ''}{' '}
                                                ($
                                                {(
                                                    ((extraSeats + 1) *
                                                        seatPriceCents) /
                                                    100
                                                ).toFixed(0)}
                                                /mo) ={' '}
                                                <span className="font-semibold text-foreground">
                                                    $
                                                    {(
                                                        (planPriceCents +
                                                            (extraSeats + 1) *
                                                                seatPriceCents) /
                                                        100
                                                    ).toFixed(0)}
                                                    /mo total
                                                </span>
                                            </p>
                                        </>
                                    ) : (
                                        <p className="text-foreground">
                                            This adds a{' '}
                                            <span className="font-semibold">
                                                $
                                                {(seatPriceCents / 100).toFixed(
                                                    0,
                                                )}
                                                /mo
                                            </span>{' '}
                                            agent seat to your subscription.
                                        </p>
                                    )}
                                </div>
                            )}

                            <DialogFooter>
                                <Button
                                    variant="outline"
                                    onClick={() => setSelectedTemplate(null)}
                                >
                                    Cancel
                                </Button>
                                {currentPlan ? (
                                    <Button
                                        onClick={() => setDialogStep(2)}
                                        disabled={loadingDetails}
                                    >
                                        Continue
                                    </Button>
                                ) : (
                                    <Button asChild>
                                        <Link href="/subscribe">
                                            Subscribe to hire
                                        </Link>
                                    </Button>
                                )}
                            </DialogFooter>
                        </>
                    )}

                    {dialogStep === 2 && (
                        <>
                            <div className="rounded-lg border border-border bg-muted/30 p-4">
                                <p className="mb-1 text-sm font-semibold text-foreground">
                                    Tools & Services
                                </p>
                                <p className="mb-3 text-xs text-muted-foreground">
                                    What should{' '}
                                    {selectedTemplate?.name?.split(' ')[0]} have
                                    access to? They'll sign up during
                                    onboarding.
                                </p>

                                {hireTools.length > 0 && (
                                    <div className="mb-3 space-y-1.5">
                                        {hireTools.map((tool, i) => (
                                            <div
                                                key={i}
                                                className="flex items-center justify-between rounded-md border border-border px-3 py-1.5"
                                            >
                                                <div className="flex items-center gap-2">
                                                    <span className="text-sm">
                                                        {tool.name}
                                                    </span>
                                                    {tool.url && (
                                                        <span className="text-xs text-muted-foreground">
                                                            {tool.url}
                                                        </span>
                                                    )}
                                                </div>
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        setHireTools(
                                                            hireTools.filter(
                                                                (_, j) =>
                                                                    j !== i,
                                                            ),
                                                        )
                                                    }
                                                    className="text-muted-foreground hover:text-destructive"
                                                >
                                                    <X className="size-3.5" />
                                                </button>
                                            </div>
                                        ))}
                                    </div>
                                )}

                                <div className="flex gap-2">
                                    <Input
                                        value={toolName}
                                        onChange={(e) =>
                                            setToolName(e.target.value)
                                        }
                                        placeholder="Tool name"
                                        className="h-8 flex-1 text-sm"
                                        onKeyDown={(e) => {
                                            if (e.key === 'Enter') {
                                                e.preventDefault();
                                                addTool();
                                            }
                                        }}
                                    />
                                    <Input
                                        value={toolUrl}
                                        onChange={(e) =>
                                            setToolUrl(e.target.value)
                                        }
                                        placeholder="URL (optional)"
                                        className="h-8 flex-1 text-sm"
                                        onKeyDown={(e) => {
                                            if (e.key === 'Enter') {
                                                e.preventDefault();
                                                addTool();
                                            }
                                        }}
                                    />
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={addTool}
                                        disabled={!toolName.trim()}
                                    >
                                        Add
                                    </Button>
                                </div>
                            </div>

                            <DialogFooter>
                                <Button
                                    variant="outline"
                                    onClick={() => setDialogStep(1)}
                                >
                                    Back
                                </Button>
                                <Button onClick={handleHire} disabled={hiring}>
                                    {hiring
                                        ? 'Hiring...'
                                        : !canCreateAgent
                                          ? `Hire ${selectedTemplate?.name} — $${(seatPriceCents / 100).toFixed(0)}/mo`
                                          : `Hire ${selectedTemplate?.name}`}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </DialogContent>
            </Dialog>

            {/* Pack hire dialog — 2 steps */}
            <Dialog
                open={selectedPack !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setSelectedPack(null);
                        setDialogStep(1);
                        setPackTools({});
                    }
                }}
            >
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle>
                            {selectedPack?.emoji} {selectedPack?.name}
                        </DialogTitle>
                        <DialogDescription>
                            {selectedPack?.tagline}
                        </DialogDescription>
                    </DialogHeader>

                    {/* Step indicator */}
                    <div className="flex items-center gap-2">
                        <div
                            className={`h-1 flex-1 rounded-full ${dialogStep >= 1 ? 'bg-foreground' : 'bg-muted'}`}
                        />
                        <div
                            className={`h-1 flex-1 rounded-full ${dialogStep >= 2 ? 'bg-foreground' : 'bg-muted'}`}
                        />
                    </div>

                    {dialogStep === 1 && (
                        <>
                            <p className="text-sm text-muted-foreground">
                                This will create{' '}
                                <span className="font-medium text-foreground">
                                    {selectedPack?.templates?.length ?? 0}{' '}
                                    agents
                                </span>{' '}
                                and deploy them to your server:
                            </p>
                            {selectedPack?.templates && (
                                <div className="space-y-2 rounded-lg bg-muted/50 p-3">
                                    {selectedPack.templates.map((t) => {
                                        const color =
                                            ROLE_COLORS[t.role] ?? '#64748b';
                                        return (
                                            <div
                                                key={t.id}
                                                className="flex items-center gap-2.5"
                                            >
                                                {t.avatar_path ? (
                                                    <img
                                                        src={`/storage/${t.avatar_path}`}
                                                        alt={t.name}
                                                        className="size-7 rounded-full object-cover"
                                                    />
                                                ) : (
                                                    <div
                                                        className="flex size-7 items-center justify-center rounded-full text-xs"
                                                        style={{
                                                            backgroundColor:
                                                                color,
                                                        }}
                                                    >
                                                        <span>{t.emoji}</span>
                                                    </div>
                                                )}
                                                <span className="text-sm font-medium">
                                                    {t.name}
                                                </span>
                                                <span className="text-xs text-muted-foreground">
                                                    {roleLabels[t.role] ??
                                                        t.role}
                                                </span>
                                            </div>
                                        );
                                    })}
                                </div>
                            )}

                            {/* Pricing */}
                            {(() => {
                                const packSize =
                                    selectedPack?.templates?.length ?? 0;
                                const newSeatsNeeded = Math.max(
                                    0,
                                    currentAgentCount +
                                        packSize -
                                        (includedAgents + extraSeats),
                                );
                                if (newSeatsNeeded > 0) {
                                    return (
                                        <div className="rounded-lg border border-border bg-muted/50 p-3 text-sm">
                                            {isOnTrial ? (
                                                <>
                                                    <p className="text-foreground">
                                                        This adds{' '}
                                                        <span className="font-semibold">
                                                            {newSeatsNeeded}{' '}
                                                            agent seat
                                                            {newSeatsNeeded !==
                                                            1
                                                                ? 's'
                                                                : ''}
                                                        </span>{' '}
                                                        ($
                                                        {(
                                                            (newSeatsNeeded *
                                                                seatPriceCents) /
                                                            100
                                                        ).toFixed(0)}
                                                        /mo). Your trial
                                                        continues — no charge
                                                        until{' '}
                                                        <span className="font-semibold">
                                                            {trialEndsAt
                                                                ? new Date(
                                                                      trialEndsAt,
                                                                  ).toLocaleDateString(
                                                                      'en-US',
                                                                      {
                                                                          month: 'short',
                                                                          day: 'numeric',
                                                                      },
                                                                  )
                                                                : 'trial ends'}
                                                        </span>
                                                        .
                                                    </p>
                                                    <p className="mt-1.5 text-xs text-muted-foreground">
                                                        After trial: $
                                                        {(
                                                            planPriceCents / 100
                                                        ).toFixed(0)}
                                                        /mo plan +{' '}
                                                        {extraSeats +
                                                            newSeatsNeeded}{' '}
                                                        seat
                                                        {extraSeats +
                                                            newSeatsNeeded !==
                                                        1
                                                            ? 's'
                                                            : ''}{' '}
                                                        ($
                                                        {(
                                                            ((extraSeats +
                                                                newSeatsNeeded) *
                                                                seatPriceCents) /
                                                            100
                                                        ).toFixed(0)}
                                                        /mo) ={' '}
                                                        <span className="font-semibold text-foreground">
                                                            $
                                                            {(
                                                                (planPriceCents +
                                                                    (extraSeats +
                                                                        newSeatsNeeded) *
                                                                        seatPriceCents) /
                                                                100
                                                            ).toFixed(0)}
                                                            /mo total
                                                        </span>
                                                    </p>
                                                </>
                                            ) : (
                                                <p className="text-foreground">
                                                    This adds{' '}
                                                    <span className="font-semibold">
                                                        {newSeatsNeeded} agent
                                                        seat
                                                        {newSeatsNeeded !== 1
                                                            ? 's'
                                                            : ''}
                                                    </span>{' '}
                                                    ($
                                                    {(
                                                        (newSeatsNeeded *
                                                            seatPriceCents) /
                                                        100
                                                    ).toFixed(0)}
                                                    /mo) to your subscription.
                                                </p>
                                            )}
                                        </div>
                                    );
                                }
                                return null;
                            })()}

                            <DialogFooter>
                                <Button
                                    variant="outline"
                                    onClick={() => setSelectedPack(null)}
                                >
                                    Cancel
                                </Button>
                                {currentPlan ? (
                                    <Button
                                        onClick={() => setDialogStep(2)}
                                        disabled={!packDetailsLoaded}
                                    >
                                        {packDetailsLoaded ? (
                                            'Continue'
                                        ) : (
                                            <>
                                                <Loader2 className="mr-2 size-4 animate-spin" />{' '}
                                                Loading...
                                            </>
                                        )}
                                    </Button>
                                ) : (
                                    <Button asChild>
                                        <Link href="/subscribe">
                                            Subscribe to hire
                                        </Link>
                                    </Button>
                                )}
                            </DialogFooter>
                        </>
                    )}

                    {dialogStep === 2 && (
                        <>
                            <p className="text-sm text-muted-foreground">
                                Set up the tools each agent should have access
                                to during onboarding.
                            </p>

                            {/* Per-agent expandable tool sections */}
                            <div className="space-y-2">
                                {(selectedPack?.templates ?? []).map((t) => {
                                    const isExpanded = expandedAgent === t.slug;
                                    const agentTools = packTools[t.slug] ?? [];
                                    const input = packToolInputs[t.slug] ?? {
                                        name: '',
                                        url: '',
                                    };
                                    const color =
                                        ROLE_COLORS[t.role] ?? '#64748b';

                                    return (
                                        <div
                                            key={t.id}
                                            className="rounded-lg border border-border"
                                        >
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    setExpandedAgent(
                                                        isExpanded
                                                            ? null
                                                            : t.slug,
                                                    )
                                                }
                                                className="flex w-full items-center gap-3 px-4 py-3"
                                            >
                                                {t.avatar_path ? (
                                                    <img
                                                        src={`/storage/${t.avatar_path}`}
                                                        alt={t.name}
                                                        className="size-7 rounded-full object-cover"
                                                    />
                                                ) : (
                                                    <div
                                                        className="flex size-7 items-center justify-center rounded-full text-xs"
                                                        style={{
                                                            backgroundColor:
                                                                color,
                                                        }}
                                                    >
                                                        <span>{t.emoji}</span>
                                                    </div>
                                                )}
                                                <div className="flex-1 text-left">
                                                    <span className="text-sm font-medium">
                                                        {t.name}
                                                    </span>
                                                    <span className="ml-2 text-xs text-muted-foreground">
                                                        {agentTools.length} tool
                                                        {agentTools.length !== 1
                                                            ? 's'
                                                            : ''}
                                                    </span>
                                                </div>
                                                {isExpanded ? (
                                                    <ChevronDown className="size-4 text-muted-foreground" />
                                                ) : (
                                                    <ChevronRight className="size-4 text-muted-foreground" />
                                                )}
                                            </button>

                                            {isExpanded && (
                                                <div className="border-t px-4 py-3">
                                                    {agentTools.length > 0 && (
                                                        <div className="mb-3 space-y-1.5">
                                                            {agentTools.map(
                                                                (tool, i) => (
                                                                    <div
                                                                        key={i}
                                                                        className="flex items-center justify-between rounded-md border border-border px-3 py-1.5"
                                                                    >
                                                                        <div className="flex items-center gap-2">
                                                                            <span className="text-sm">
                                                                                {
                                                                                    tool.name
                                                                                }
                                                                            </span>
                                                                            {tool.url && (
                                                                                <span className="text-xs text-muted-foreground">
                                                                                    {
                                                                                        tool.url
                                                                                    }
                                                                                </span>
                                                                            )}
                                                                        </div>
                                                                        <button
                                                                            type="button"
                                                                            onClick={() =>
                                                                                setPackTools(
                                                                                    {
                                                                                        ...packTools,
                                                                                        [t.slug]:
                                                                                            agentTools.filter(
                                                                                                (
                                                                                                    _,
                                                                                                    j,
                                                                                                ) =>
                                                                                                    j !==
                                                                                                    i,
                                                                                            ),
                                                                                    },
                                                                                )
                                                                            }
                                                                            className="text-muted-foreground hover:text-destructive"
                                                                        >
                                                                            <X className="size-3.5" />
                                                                        </button>
                                                                    </div>
                                                                ),
                                                            )}
                                                        </div>
                                                    )}

                                                    <div className="flex gap-2">
                                                        <Input
                                                            value={input.name}
                                                            onChange={(e) =>
                                                                setPackToolInputs(
                                                                    {
                                                                        ...packToolInputs,
                                                                        [t.slug]:
                                                                            {
                                                                                ...input,
                                                                                name: e
                                                                                    .target
                                                                                    .value,
                                                                            },
                                                                    },
                                                                )
                                                            }
                                                            placeholder="Tool name"
                                                            className="h-8 flex-1 text-sm"
                                                            onKeyDown={(e) => {
                                                                if (
                                                                    e.key ===
                                                                    'Enter'
                                                                ) {
                                                                    e.preventDefault();
                                                                    addPackTool(
                                                                        t.slug,
                                                                    );
                                                                }
                                                            }}
                                                        />
                                                        <Input
                                                            value={input.url}
                                                            onChange={(e) =>
                                                                setPackToolInputs(
                                                                    {
                                                                        ...packToolInputs,
                                                                        [t.slug]:
                                                                            {
                                                                                ...input,
                                                                                url: e
                                                                                    .target
                                                                                    .value,
                                                                            },
                                                                    },
                                                                )
                                                            }
                                                            placeholder="URL (optional)"
                                                            className="h-8 flex-1 text-sm"
                                                            onKeyDown={(e) => {
                                                                if (
                                                                    e.key ===
                                                                    'Enter'
                                                                ) {
                                                                    e.preventDefault();
                                                                    addPackTool(
                                                                        t.slug,
                                                                    );
                                                                }
                                                            }}
                                                        />
                                                        <Button
                                                            type="button"
                                                            variant="outline"
                                                            size="sm"
                                                            onClick={() =>
                                                                addPackTool(
                                                                    t.slug,
                                                                )
                                                            }
                                                            disabled={
                                                                !input.name?.trim()
                                                            }
                                                        >
                                                            Add
                                                        </Button>
                                                    </div>
                                                </div>
                                            )}
                                        </div>
                                    );
                                })}
                            </div>

                            <DialogFooter>
                                <Button
                                    variant="outline"
                                    onClick={() => setDialogStep(1)}
                                >
                                    Back
                                </Button>
                                <Button
                                    onClick={handlePackHire}
                                    disabled={hiring}
                                >
                                    {hiring
                                        ? 'Deploying...'
                                        : `Deploy ${selectedPack?.templates?.length ?? 0} Agents`}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
