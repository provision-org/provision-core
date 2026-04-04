import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, Check, X } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import type { Server, SharedData } from '@/types';

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

type Step =
    | 'name'
    | 'mode'
    | 'email'
    | 'role'
    | 'tone'
    | 'trait'
    | 'emoji'
    | 'backstory'
    | 'tools'
    | 'model';

const ALL_STEPS: Step[] = [
    'name',
    'mode',
    'email',
    'role',
    'tone',
    'trait',
    'emoji',
    'backstory',
    'tools',
    'model',
];

const toneOptions = [
    {
        value: 'all business',
        label: 'All business',
        description: 'Sharp, focused, zero fluff',
    },
    {
        value: 'friendly pro',
        label: 'Friendly pro',
        description: 'Warm but keeps it polished',
    },
    {
        value: 'casual vibes',
        label: 'Casual vibes',
        description: 'Relaxed, like a real teammate',
    },
    {
        value: 'unfiltered',
        label: 'Unfiltered',
        description: 'Bold, opinionated, unapologetically real',
    },
];

const traitOptions = [
    {
        value: 'the overachiever',
        label: 'The overachiever',
        description: 'Goes above and beyond, every single time',
    },
    {
        value: 'the straight talker',
        label: 'The straight talker',
        description: 'Honest feedback, even when it stings',
    },
    {
        value: 'the deep diver',
        label: 'The deep diver',
        description: "Won't rest until every detail is perfect",
    },
    {
        value: 'the spark plug',
        label: 'The spark plug',
        description: 'High energy, always pushing things forward',
    },
];

const emojiOptions = [
    '🤖',
    '🦊',
    '🐺',
    '🦅',
    '🐙',
    '🦉',
    '🐝',
    '🔥',
    '⚡',
    '💎',
    '✨',
    '🌊',
    '🎯',
    '🚀',
    '🧠',
    '🎭',
    '🌙',
    '🎸',
    '🎨',
    '🔮',
    '💫',
];

type ModelMeta = {
    label: string;
    description: string;
    tier: 'pro' | 'standard' | 'lite';
    cost: string;
    sort: number;
};

const modelMeta: Record<string, ModelMeta> = {
    'claude-opus-4-6': {
        label: 'Claude Opus 4.6',
        description: 'Most capable reasoning',
        tier: 'pro',
        cost: '$$$',
        sort: 1,
    },
    'gpt-5.4': {
        label: 'GPT-5.4',
        description: 'Latest & most capable',
        tier: 'pro',
        cost: '$$$',
        sort: 2,
    },
    'gpt-5.2-codex': {
        label: 'GPT-5.2 Codex',
        description: 'Code specialist',
        tier: 'pro',
        cost: '$$$',
        sort: 3,
    },
    'claude-opus-4-5': {
        label: 'Claude Opus 4.5',
        description: 'Previous gen flagship',
        tier: 'pro',
        cost: '$$$',
        sort: 4,
    },
    'claude-sonnet-4-6': {
        label: 'Claude Sonnet 4.6',
        description: 'Fast & capable',
        tier: 'standard',
        cost: '$$',
        sort: 10,
    },
    'gpt-5-mini': {
        label: 'GPT-5 Mini',
        description: 'Balanced performance',
        tier: 'standard',
        cost: '$$',
        sort: 11,
    },
    'z-ai/glm-5': {
        label: 'GLM-5',
        description: 'Strong all-rounder',
        tier: 'standard',
        cost: '$$',
        sort: 12,
    },
    'moonshotai/kimi-k2.5': {
        label: 'Kimi K2.5',
        description: 'Strong reasoning',
        tier: 'standard',
        cost: '$$',
        sort: 13,
    },
    'moonshotai/kimi-k2-thinking': {
        label: 'Kimi K2 Thinking',
        description: 'Deep thinking',
        tier: 'standard',
        cost: '$$',
        sort: 14,
    },
    'gpt-5-nano': {
        label: 'GPT-5 Nano',
        description: 'Ultra-fast responses',
        tier: 'lite',
        cost: '$',
        sort: 20,
    },
    'z-ai/glm-4.7': {
        label: 'GLM-4.7',
        description: 'Fast & affordable',
        tier: 'lite',
        cost: '$',
        sort: 21,
    },
};

const tierConfig = {
    pro: {
        label: 'Pro',
        className:
            'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
    },
    standard: {
        label: 'Standard',
        className:
            'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
    },
    lite: {
        label: 'Lite',
        className:
            'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
    },
};

/* ═══ Animated step wrapper ═══ */

function StepTransition({
    stepKey,
    direction,
    children,
}: {
    stepKey: string;
    direction: 'forward' | 'backward';
    children: React.ReactNode;
}) {
    const ref = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const el = ref.current;
        if (!el) return;

        const from = direction === 'forward' ? 40 : -40;
        el.style.opacity = '0';
        el.style.transform = `translateY(${from}px)`;

        requestAnimationFrame(() => {
            el.style.transition =
                'opacity 0.4s cubic-bezier(0.16, 1, 0.3, 1), transform 0.4s cubic-bezier(0.16, 1, 0.3, 1)';
            el.style.opacity = '1';
            el.style.transform = 'translateX(0)';
        });
    }, [stepKey, direction]);

    return (
        <div ref={ref} key={stepKey}>
            {children}
        </div>
    );
}

/* ═══ Live badge preview ═══ */

function BadgePreview({
    name,
    emoji,
    tone,
    trait,
    model,
    harness,
    emailPrefix,
    emailDomain,
    stepIndex,
}: {
    name: string;
    emoji: string;
    tone: string;
    trait: string;
    model: string;
    harness: string;
    emailPrefix: string;
    emailDomain?: string | null;
    stepIndex: number;
}) {
    const hasName = name.trim().length > 0;
    const meta = model ? modelMeta[model] : null;
    const tags: string[] = [];
    if (tone) tags.push(tone);
    if (trait) tags.push(trait);
    if (model && stepIndex >= ALL_STEPS.indexOf('model'))
        tags.push(meta?.label ?? model);

    const generatedId = hasName
        ? `PRV-${name
              .toUpperCase()
              .replace(/[^A-Z0-9]/g, '')
              .slice(0, 4)}-9984`
        : 'PRV-XXXX-0000';

    return (
        <div
            className="sticky top-12 flex flex-col items-center transition-all duration-700"
            style={{
                transformOrigin: 'top center',
                animation: 'sway 8s ease-in-out infinite alternate',
            }}
        >
            <style>{`
                @keyframes sway {
                    0% { transform: rotate(-1.5deg); }
                    100% { transform: rotate(1.5deg); }
                }
            `}</style>

            {/* Lanyard strap */}
            <div className="h-32 w-[24px] rounded-[3px] bg-gradient-to-b from-primary/30 via-primary/70 to-primary shadow-inner" />
            {/* Metal clip */}
            <div className="z-10 -mt-1 mb-[-4px] h-[24px] w-[32px] rounded-b-[6px] border-2 border-t-0 border-muted-foreground/30 bg-gradient-to-b from-muted to-muted-foreground/20 shadow-md" />

            {/* Badge card */}
            <div className="relative w-[320px] overflow-hidden rounded-[24px] border border-border/50 bg-card shadow-[0_20px_50px_-12px_rgba(0,0,0,0.3)] transition-all duration-500">
                {/* Holographic overlay */}
                <div className="pointer-events-none absolute inset-0 bg-gradient-to-tr from-primary/5 via-transparent to-primary/10 opacity-40 mix-blend-overlay" />

                {/* Header */}
                <div className="relative border-b border-border/30 bg-muted/30 px-6 pt-10 pb-6 text-center">
                    <div className="absolute top-4 right-5 text-[9px] font-bold tracking-widest text-muted-foreground/40 uppercase">
                        {harness ? harness : 'PROVISION'}
                    </div>

                    {/* Avatar Area */}
                    <div
                        className={cn(
                            'relative mx-auto mb-5 flex size-24 items-center justify-center overflow-hidden rounded-2xl shadow-lg ring-4 ring-background transition-all duration-500',
                            hasName ? 'bg-primary/10' : 'bg-muted',
                        )}
                    >
                        {emoji ? (
                            <span className="text-4xl drop-shadow-md">
                                {emoji}
                            </span>
                        ) : hasName ? (
                            <span className="text-3xl font-black text-primary/60">
                                {name
                                    .split(/\s+/)
                                    .map((w) => w[0])
                                    .join('')
                                    .slice(0, 2)
                                    .toUpperCase()}
                            </span>
                        ) : (
                            <span className="text-2xl text-muted-foreground/20">
                                ?
                            </span>
                        )}
                    </div>

                    {/* Name */}
                    <p
                        className={cn(
                            'text-xl font-bold tracking-tight transition-all duration-500',
                            hasName
                                ? 'text-foreground'
                                : 'text-muted-foreground/20',
                        )}
                    >
                        {hasName ? name : 'AGENT NAME'}
                    </p>

                    {/* Email */}
                    {emailDomain && (
                        <p
                            className={cn(
                                'mt-1 text-[10px] font-medium opacity-50',
                                emailPrefix
                                    ? 'text-primary'
                                    : 'text-muted-foreground',
                            )}
                        >
                            {emailPrefix
                                ? `${emailPrefix}@${emailDomain}`
                                : `email@${emailDomain}`}
                        </p>
                    )}
                </div>

                {/* Body Details */}
                <div className="space-y-5 px-6 py-6">
                    <div className="flex items-end justify-between">
                        <div>
                            <p className="mb-1 text-[9px] font-bold tracking-widest text-muted-foreground/40 uppercase">
                                ID Number
                            </p>
                            <p className="font-mono text-xs font-semibold">
                                {generatedId}
                            </p>
                        </div>
                        <div className="text-right">
                            <p className="mb-1 text-[9px] font-bold tracking-widest text-muted-foreground/40 uppercase">
                                Status
                            </p>
                            <p className="text-xs font-semibold text-primary/80">
                                Pending...
                            </p>
                        </div>
                    </div>

                    <div>
                        <p className="mb-2 text-[9px] font-bold tracking-widest text-muted-foreground/40 uppercase">
                            Profiles
                        </p>
                        <div className="flex min-h-[28px] flex-wrap gap-1.5">
                            {tags.length > 0 ? (
                                tags.map((tag) => (
                                    <span
                                        key={tag}
                                        className="rounded-md border border-border/30 bg-muted/50 px-2 py-0.5 text-[10px] font-medium text-foreground/70"
                                    >
                                        {tag}
                                    </span>
                                ))
                            ) : (
                                <span className="text-[10px] text-muted-foreground/30 italic">
                                    Awaiting personality
                                </span>
                            )}
                        </div>
                    </div>

                    <div className="flex items-center justify-between border-t border-border/40 pt-2">
                        <div className="h-5 w-20 bg-[url('data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIxMDAlIiBoZWlnaHQ9IjEwMCUiPjxyZWN0IHdpZHRoPSIxIiBoZWlnaHQ9IjEwMCUiIGZpbGw9ImN1cnJlbnRDb2xvciIgeD0iMCIvPjxyZWN0IHdpZHRoPSIyIiBoZWlnaHQ9IjEwMCUiIGZpbGw9ImN1cnJlbnRDb2xvciIgeD0iMyIvPjxyZWN0IHdpZHRoPSIxIiBoZWlnaHQ9IjEwMCUiIGZpbGw9ImN1cnJlbnRDb2xvciIgeD0iNyIvPjxyZWN0IHdpZHRoPSIzIiBoZWlnaHQ9IjEwMCUiIGZpbGw9ImN1cnJlbnRDb2xvciIgeD0iMTAiLz48cmVjdCB3aWR0aD0iMiIgaGVpZ2h0PSIxMDAlIiBmaWxsPSJjdXJyZW50Q29sb3IiIHg9IjE1Ii8+PHJlY3Qgd2lkdGg9IjEiIGhlaWdodD0iMTAwJSIgZmlsbD0iY3VycmVudENvbG9yIiB4PSIyMCIvPjxyZWN0IHdpZHRoPSI0IiBoZWlnaHQ9IjEwMCUiIGZpbGw9ImN1cnJlbnRDb2xvciIgeD0iMjMiLz48cmVjdCB3aWR0aD0iMSIgaGVpZ2h0PSIxMDAlIiBmaWxsPSJjdXJyZW50Q29sb3IiIHg9IjI5Ii8+PHJlY3Qgd2lkdGg9IjIiIGhlaWdodD0iMTAwJSIgZmlsbD0iY3VycmVudENvbG9yIiB4PSIzMiIvPjwvc3ZnPg==')] bg-repeat-x opacity-30" />
                        <div className="size-2 rounded-full bg-muted-foreground/20" />
                    </div>
                </div>
            </div>
        </div>
    );
}

/* ═══ Main page ═══ */

export default function CreateAgent({
    availableModels,
    // eslint-disable-next-line @typescript-eslint/no-unused-vars
    defaultModel,
    modelTiers = [],
    defaultTier = 'powerful',
    // eslint-disable-next-line @typescript-eslint/no-unused-vars
    canCreateAgent = false,
    needsSeat = false,
    seatPriceMonthly = '49',
    emailDomain,
    teamSlug = '',
    isOnTrial = false,
    trialEndsAt,
    planPriceCents = 4900,
    extraSeats = 0,
}: {
    server: Server | null;
    availableModels: AvailableModel[];
    defaultModel: string;
    modelTiers?: ModelTierOption[];
    defaultTier?: string;
    canCreateAgent?: boolean;
    needsSeat?: boolean;
    seatPriceMonthly?: string;
    emailDomain?: string | null;
    teamSlug?: string;
    isOnTrial?: boolean;
    trialEndsAt?: string;
    planPriceCents?: number;
    extraSeats?: number;
}) {
    const { auth } = usePage<SharedData>().props;
    const [step, setStep] = useState<Step>('name');
    const [direction, setDirection] = useState<'forward' | 'backward'>(
        'forward',
    );
    const [showAdvancedModel, setShowAdvancedModel] = useState(false);

    const [toolName, setToolName] = useState('');
    const [toolUrl, setToolUrl] = useState('');

    const form = useForm({
        name: '',
        agent_mode: 'channel' as 'channel' | 'workforce',
        email_prefix: '',
        role: 'custom',
        job_description: '',
        model_tier: defaultTier,
        model_primary: '',
        emoji: '',
        personality: '',
        communication_style: '',
        backstory: '',
        tools: [] as { name: string; url: string }[],
        reports_to: '' as string,
        org_title: '',
    }).withPrecognition('post', '/agents');

    form.setValidationTimeout(500);

    const allSteps = useMemo(
        () =>
            emailDomain ? ALL_STEPS : ALL_STEPS.filter((s) => s !== 'email'),
        [emailDomain],
    );

    const stepIndex = allSteps.indexOf(step);

    function next() {
        const i = stepIndex + 1;
        if (i >= allSteps.length) return;

        // Validate name before advancing to email step
        if (step === 'name') {
            // Auto-populate email prefix
            if (!form.data.email_prefix) {
                const slug = form.data.name
                    .toLowerCase()
                    .replace(/[^a-z0-9]+/g, '_')
                    .replace(/^_|_$/g, '');
                const defaultPrefix = teamSlug ? `${slug}_${teamSlug}` : slug;
                form.setData('email_prefix', defaultPrefix);
            }
            form.validate({
                only: ['name'],
                onSuccess: () => {
                    setDirection('forward');
                    setStep(allSteps[i]);
                },
            });
            return;
        }

        // Validate email prefix before advancing past email step
        if (step === 'email') {
            form.validate({
                only: ['email_prefix'],
                onSuccess: () => {
                    setDirection('forward');
                    setStep(allSteps[i]);
                },
            });
            return;
        }

        setDirection('forward');
        setStep(allSteps[i]);
    }

    function back() {
        const i = stepIndex - 1;
        if (i >= 0) {
            setDirection('backward');
            setStep(allSteps[i]);
        }
    }

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        form.post('/agents');
    }

    return (
        <div className="relative flex min-h-svh flex-col overflow-hidden bg-background">
            <Head title="Create Agent" />

            {/* Top bar */}
            <div className="absolute top-0 right-0 left-0 z-20 px-6 pt-5">
                <Link
                    href="/agents"
                    className="inline-flex items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
                >
                    <ArrowLeft className="size-3.5" />
                    Agents
                </Link>
            </div>

            <div className="relative mx-auto flex w-full max-w-7xl flex-1">
                {/* Left Column - Form */}
                <div className="z-10 mx-auto flex min-h-svh w-full max-w-2xl flex-1 flex-col justify-center px-8 py-24 lg:px-20">
                    <div className="mx-auto w-full transition-all duration-500">
                        {/* Progress Line */}
                        <div className="absolute top-0 right-0 left-0 z-30 h-1 bg-muted/20">
                            <div
                                className="h-full bg-primary shadow-[0_0_12px_rgba(var(--primary),0.6)] transition-all duration-700 ease-out"
                                style={{
                                    width: `${((stepIndex + 1) / allSteps.length) * 100}%`,
                                }}
                            />
                        </div>

                        <div className="mb-12">
                            <span className="mb-1 block text-[10px] font-bold tracking-widest text-primary uppercase">
                                Step {stepIndex + 1} of {allSteps.length}
                            </span>
                            <h2 className="text-sm font-semibold tracking-tight text-muted-foreground capitalize">
                                {step.replace('_', ' ')}
                            </h2>
                        </div>

                        {/* Steps Content */}
                        <div className="mx-auto w-full max-w-md">
                            <StepTransition
                                stepKey={step}
                                direction={direction}
                            >
                                {/* ── Name ── */}
                                {step === 'name' && (
                                    <div className="flex flex-col items-center gap-6">
                                        <div className="text-center">
                                            <h1 className="text-2xl font-bold tracking-tight">
                                                Name your AI team member
                                            </h1>
                                            <p className="mt-2 text-sm text-muted-foreground">
                                                Give them a name and pick a role
                                                to get started.
                                            </p>
                                        </div>

                                        <Input
                                            value={form.data.name}
                                            onChange={(e) =>
                                                form.setData(
                                                    'name',
                                                    e.target.value,
                                                )
                                            }
                                            placeholder="Luna, Jarvis, Buddy..."
                                            className="h-12 text-center text-base"
                                            autoFocus
                                            onKeyDown={(e) => {
                                                if (
                                                    e.key === 'Enter' &&
                                                    form.data.name.trim()
                                                )
                                                    next();
                                            }}
                                        />

                                        <Button
                                            size="lg"
                                            onClick={next}
                                            disabled={!form.data.name.trim()}
                                        >
                                            Continue
                                        </Button>
                                    </div>
                                )}

                                {/* ── Mode ── */}
                                {step === 'mode' && (
                                    <div className="flex flex-col items-center gap-6">
                                        <div className="text-center">
                                            <h1 className="text-2xl font-bold tracking-tight">
                                                What type of agent?
                                            </h1>
                                            <p className="mt-2 text-sm text-muted-foreground">
                                                Choose how {form.data.name} will
                                                work with your team.
                                            </p>
                                        </div>

                                        <div className="grid w-full grid-cols-2 gap-4">
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    form.setData(
                                                        'agent_mode',
                                                        'channel',
                                                    )
                                                }
                                                className={`flex flex-col items-center gap-3 rounded-xl border-2 p-6 text-center transition-all ${
                                                    form.data.agent_mode ===
                                                    'channel'
                                                        ? 'border-primary bg-primary/5'
                                                        : 'border-border hover:border-border/80 hover:bg-accent/30'
                                                }`}
                                            >
                                                <span className="text-3xl">
                                                    {'💬'}
                                                </span>
                                                <div>
                                                    <p className="text-sm font-semibold">
                                                        Channel Agent
                                                    </p>
                                                    <p className="mt-1 text-xs text-muted-foreground">
                                                        Communicates via Slack,
                                                        Telegram, Discord, or
                                                        web chat.
                                                    </p>
                                                </div>
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    form.setData(
                                                        'agent_mode',
                                                        'workforce',
                                                    )
                                                }
                                                className={`flex flex-col items-center gap-3 rounded-xl border-2 p-6 text-center transition-all ${
                                                    form.data.agent_mode ===
                                                    'workforce'
                                                        ? 'border-primary bg-primary/5'
                                                        : 'border-border hover:border-border/80 hover:bg-accent/30'
                                                }`}
                                            >
                                                <span className="text-3xl">
                                                    {'⚡'}
                                                </span>
                                                <div>
                                                    <p className="text-sm font-semibold">
                                                        Workforce Agent
                                                    </p>
                                                    <p className="mt-1 text-xs text-muted-foreground">
                                                        Executes tasks
                                                        autonomously within the
                                                        org hierarchy.
                                                    </p>
                                                </div>
                                            </button>
                                        </div>

                                        {form.data.agent_mode ===
                                            'workforce' && (
                                            <div className="w-full space-y-3">
                                                <div className="space-y-1.5">
                                                    <label className="text-sm font-medium">
                                                        Org Title
                                                    </label>
                                                    <Input
                                                        value={
                                                            form.data.org_title
                                                        }
                                                        onChange={(e) =>
                                                            form.setData(
                                                                'org_title',
                                                                e.target.value,
                                                            )
                                                        }
                                                        placeholder="e.g. VP of Engineering"
                                                    />
                                                </div>
                                            </div>
                                        )}

                                        <Button size="lg" onClick={next}>
                                            Continue
                                        </Button>
                                    </div>
                                )}

                                {/* ── Email ── */}
                                {step === 'email' && (
                                    <div className="flex flex-col items-center gap-6">
                                        <div className="text-center">
                                            <h1 className="text-2xl font-bold tracking-tight">
                                                {form.data.name}'s email address
                                            </h1>
                                            <p className="mt-2 text-sm text-muted-foreground">
                                                Your agent will receive and send
                                                emails from this address.
                                            </p>
                                        </div>

                                        <div className="w-full">
                                            <div className="flex items-center gap-0">
                                                <Input
                                                    value={
                                                        form.data.email_prefix
                                                    }
                                                    onChange={(e) => {
                                                        const val =
                                                            e.target.value
                                                                .toLowerCase()
                                                                .replace(
                                                                    /[^a-z0-9._-]/g,
                                                                    '',
                                                                );
                                                        form.setData(
                                                            'email_prefix',
                                                            val,
                                                        );
                                                        form.validate(
                                                            'email_prefix',
                                                        );
                                                    }}
                                                    className="h-12 rounded-r-none border-r-0 text-right text-base"
                                                    placeholder="luna_acme"
                                                    autoFocus
                                                />
                                                <div className="flex h-12 items-center rounded-r-lg border border-l-0 border-input bg-muted px-3 text-sm text-muted-foreground">
                                                    @{emailDomain}
                                                </div>
                                            </div>

                                            {/* Availability indicator */}
                                            <div className="mt-2 flex items-center gap-1.5 text-sm">
                                                {form.validating && (
                                                    <span className="text-muted-foreground">
                                                        Checking availability...
                                                    </span>
                                                )}
                                                {!form.validating &&
                                                    form.valid(
                                                        'email_prefix',
                                                    ) && (
                                                        <>
                                                            <Check className="size-3.5 text-green-600" />
                                                            <span className="text-green-600">
                                                                Available
                                                            </span>
                                                        </>
                                                    )}
                                                {!form.validating &&
                                                    form.invalid(
                                                        'email_prefix',
                                                    ) && (
                                                        <>
                                                            <X className="size-3.5 text-destructive" />
                                                            <span className="text-destructive">
                                                                {
                                                                    form.errors
                                                                        .email_prefix
                                                                }
                                                            </span>
                                                        </>
                                                    )}
                                            </div>
                                        </div>

                                        <div className="flex w-full items-center justify-between">
                                            <Button
                                                variant="ghost"
                                                onClick={back}
                                            >
                                                Back
                                            </Button>
                                            <Button
                                                onClick={next}
                                                disabled={
                                                    !form.data.email_prefix ||
                                                    form.invalid(
                                                        'email_prefix',
                                                    ) ||
                                                    form.validating
                                                }
                                            >
                                                Continue
                                            </Button>
                                        </div>
                                    </div>
                                )}

                                {/* ── Role / Job Description ── */}
                                {step === 'role' && (
                                    <div className="flex flex-col items-center gap-6">
                                        <div className="text-center">
                                            <h1 className="text-2xl font-bold tracking-tight">
                                                What's {form.data.name}'s job?
                                            </h1>
                                            <p className="mt-2 text-sm text-muted-foreground">
                                                Describe what {form.data.name}{' '}
                                                should do — like a job
                                                description. The more detail,
                                                the better.
                                            </p>
                                        </div>

                                        <div className="w-full">
                                            <textarea
                                                value={
                                                    form.data.job_description
                                                }
                                                onChange={(e) =>
                                                    form.setData(
                                                        'job_description',
                                                        e.target.value,
                                                    )
                                                }
                                                maxLength={5000}
                                                rows={6}
                                                className="w-full rounded-lg border border-input bg-transparent px-4 py-3 text-sm shadow-xs transition-[color,box-shadow] outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                                placeholder={`e.g. Pull daily reports from Mixpanel and Google Analytics. Summarize key metrics (DAU, retention, conversion) and post a morning brief to the team channel every day at 9am.\n\nMonitor our GitHub repos for new issues and triage them by priority.`}
                                                autoFocus
                                            />
                                            <p className="mt-1.5 text-right text-xs text-muted-foreground">
                                                {
                                                    form.data.job_description
                                                        .length
                                                }
                                                /5000
                                            </p>
                                        </div>

                                        <SkipNavButtons
                                            onBack={back}
                                            onNext={next}
                                        />
                                    </div>
                                )}

                                {/* ── Tone ── */}
                                {step === 'tone' && (
                                    <OptionStep
                                        heading="Set the tone"
                                        subtext={`How should ${form.data.name} come across?`}
                                        options={toneOptions}
                                        selected={form.data.communication_style}
                                        onSelect={(v) =>
                                            form.setData(
                                                'communication_style',
                                                v,
                                            )
                                        }
                                        onBack={back}
                                        onNext={next}
                                    />
                                )}

                                {/* ── Trait ── */}
                                {step === 'trait' && (
                                    <OptionStep
                                        heading="Personality"
                                        subtext={`What makes ${form.data.name} different?`}
                                        options={traitOptions}
                                        selected={form.data.personality}
                                        onSelect={(v) =>
                                            form.setData('personality', v)
                                        }
                                        onBack={back}
                                        onNext={next}
                                    />
                                )}

                                {/* ── Emoji ── */}
                                {step === 'emoji' && (
                                    <div className="flex flex-col items-center gap-6">
                                        <div className="text-center">
                                            <h1 className="text-2xl font-bold tracking-tight">
                                                Choose a signature
                                            </h1>
                                            <p className="mt-2 text-sm text-muted-foreground">
                                                Pick an emoji that represents{' '}
                                                {form.data.name}
                                            </p>
                                        </div>

                                        <div className="grid grid-cols-7 gap-2">
                                            {emojiOptions.map((emoji) => (
                                                <button
                                                    key={emoji}
                                                    type="button"
                                                    onClick={() =>
                                                        form.setData(
                                                            'emoji',
                                                            form.data.emoji ===
                                                                emoji
                                                                ? ''
                                                                : emoji,
                                                        )
                                                    }
                                                    className={cn(
                                                        'flex size-12 items-center justify-center rounded-xl text-xl transition-all',
                                                        form.data.emoji ===
                                                            emoji
                                                            ? 'bg-accent ring-2 ring-foreground ring-offset-2 ring-offset-background'
                                                            : 'hover:bg-accent/50',
                                                    )}
                                                >
                                                    {emoji}
                                                </button>
                                            ))}
                                        </div>

                                        <NavButtons
                                            onBack={back}
                                            onNext={next}
                                        />
                                    </div>
                                )}

                                {/* ── Backstory ── */}
                                {step === 'backstory' && (
                                    <div className="flex flex-col items-center gap-6">
                                        <div className="text-center">
                                            <h1 className="text-2xl font-bold tracking-tight">
                                                Anything else?
                                            </h1>
                                            <p className="mt-2 text-sm text-muted-foreground">
                                                Optional — add backstory,
                                                quirks, or special instructions
                                            </p>
                                        </div>

                                        <div className="w-full">
                                            <textarea
                                                value={form.data.backstory}
                                                onChange={(e) =>
                                                    form.setData(
                                                        'backstory',
                                                        e.target.value,
                                                    )
                                                }
                                                maxLength={500}
                                                rows={4}
                                                className="w-full rounded-lg border border-input bg-transparent px-4 py-3 text-sm shadow-xs transition-[color,box-shadow] outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                                placeholder={`e.g. ${form.data.name} has an encyclopedic knowledge of 90s pop culture and works it into every conversation...`}
                                                autoFocus
                                            />
                                            <p className="mt-1.5 text-right text-xs text-muted-foreground">
                                                {form.data.backstory.length}/500
                                            </p>
                                        </div>

                                        <SkipNavButtons
                                            onBack={back}
                                            onNext={next}
                                        />
                                    </div>
                                )}

                                {/* ── Tools ── */}
                                {step === 'tools' && (
                                    <div className="flex flex-col items-center gap-6">
                                        <div className="text-center">
                                            <h1 className="text-2xl font-bold tracking-tight">
                                                What tools does {form.data.name}{' '}
                                                need?
                                            </h1>
                                            <p className="mt-2 text-sm text-muted-foreground">
                                                List the services{' '}
                                                {form.data.name} should have
                                                access to. They'll sign up or
                                                request invites during
                                                onboarding.
                                            </p>
                                        </div>

                                        {/* Tool input row */}
                                        <div className="flex w-full gap-2">
                                            <Input
                                                value={toolName}
                                                onChange={(e) =>
                                                    setToolName(e.target.value)
                                                }
                                                placeholder="Tool name (e.g. HubSpot)"
                                                className="flex-1"
                                                autoFocus
                                                onKeyDown={(e) => {
                                                    if (
                                                        e.key === 'Enter' &&
                                                        toolName.trim()
                                                    ) {
                                                        e.preventDefault();
                                                        form.setData('tools', [
                                                            ...form.data.tools,
                                                            {
                                                                name: toolName.trim(),
                                                                url: toolUrl.trim(),
                                                            },
                                                        ]);
                                                        setToolName('');
                                                        setToolUrl('');
                                                    }
                                                }}
                                            />
                                            <Input
                                                value={toolUrl}
                                                onChange={(e) =>
                                                    setToolUrl(e.target.value)
                                                }
                                                placeholder="Website URL (optional)"
                                                className="flex-1"
                                                onKeyDown={(e) => {
                                                    if (
                                                        e.key === 'Enter' &&
                                                        toolName.trim()
                                                    ) {
                                                        e.preventDefault();
                                                        form.setData('tools', [
                                                            ...form.data.tools,
                                                            {
                                                                name: toolName.trim(),
                                                                url: toolUrl.trim(),
                                                            },
                                                        ]);
                                                        setToolName('');
                                                        setToolUrl('');
                                                    }
                                                }}
                                            />
                                            <Button
                                                type="button"
                                                variant="outline"
                                                onClick={() => {
                                                    if (toolName.trim()) {
                                                        form.setData('tools', [
                                                            ...form.data.tools,
                                                            {
                                                                name: toolName.trim(),
                                                                url: toolUrl.trim(),
                                                            },
                                                        ]);
                                                        setToolName('');
                                                        setToolUrl('');
                                                    }
                                                }}
                                                disabled={!toolName.trim()}
                                            >
                                                Add
                                            </Button>
                                        </div>

                                        {/* Tool list */}
                                        {form.data.tools.length > 0 && (
                                            <div className="w-full space-y-2">
                                                {form.data.tools.map(
                                                    (tool, i) => (
                                                        <div
                                                            key={i}
                                                            className="flex items-center justify-between rounded-lg border border-border px-4 py-2.5"
                                                        >
                                                            <div>
                                                                <span className="text-sm font-medium">
                                                                    {tool.name}
                                                                </span>
                                                                {tool.url && (
                                                                    <span className="ml-2 text-xs text-muted-foreground">
                                                                        {
                                                                            tool.url
                                                                        }
                                                                    </span>
                                                                )}
                                                            </div>
                                                            <button
                                                                type="button"
                                                                onClick={() => {
                                                                    form.setData(
                                                                        'tools',
                                                                        form.data.tools.filter(
                                                                            (
                                                                                _,
                                                                                j,
                                                                            ) =>
                                                                                j !==
                                                                                i,
                                                                        ),
                                                                    );
                                                                }}
                                                                className="text-muted-foreground hover:text-destructive"
                                                            >
                                                                <X className="size-4" />
                                                            </button>
                                                        </div>
                                                    ),
                                                )}
                                            </div>
                                        )}

                                        {/* Common suggestions based on role */}
                                        {form.data.tools.length === 0 && (
                                            <div className="w-full">
                                                <p className="mb-2 text-xs text-muted-foreground">
                                                    Common tools:
                                                </p>
                                                <div className="flex flex-wrap gap-2">
                                                    {[
                                                        'HubSpot',
                                                        'Ahrefs',
                                                        'Google Analytics',
                                                        'Mixpanel',
                                                        'Linear',
                                                        'Notion',
                                                        'Slack',
                                                        'Zendesk',
                                                    ].map((suggestion) => (
                                                        <button
                                                            key={suggestion}
                                                            type="button"
                                                            onClick={() => {
                                                                form.setData(
                                                                    'tools',
                                                                    [
                                                                        ...form
                                                                            .data
                                                                            .tools,
                                                                        {
                                                                            name: suggestion,
                                                                            url: '',
                                                                        },
                                                                    ],
                                                                );
                                                            }}
                                                            className="rounded-full border border-border px-3 py-1 text-xs text-muted-foreground transition-colors hover:border-foreground/20 hover:text-foreground"
                                                        >
                                                            + {suggestion}
                                                        </button>
                                                    ))}
                                                </div>
                                            </div>
                                        )}

                                        <SkipNavButtons
                                            onBack={back}
                                            onNext={next}
                                        />
                                    </div>
                                )}

                                {/* ── Model ── */}
                                {step === 'model' && (
                                    <form
                                        onSubmit={handleSubmit}
                                        className="flex flex-col items-center gap-6"
                                    >
                                        <div className="text-center">
                                            <h1 className="text-2xl font-bold tracking-tight">
                                                How should {form.data.name}{' '}
                                                think?
                                            </h1>
                                            <p className="mt-2 text-sm text-muted-foreground">
                                                Choose the right balance of
                                                speed and intelligence.
                                            </p>
                                        </div>

                                        {!showAdvancedModel ? (
                                            <>
                                                <div className="grid w-full grid-cols-2 gap-4">
                                                    {modelTiers.map((tier) => (
                                                        <button
                                                            key={tier.value}
                                                            type="button"
                                                            onClick={() => {
                                                                form.setData(
                                                                    'model_tier',
                                                                    tier.value,
                                                                );
                                                                form.setData(
                                                                    'model_primary',
                                                                    '',
                                                                );
                                                            }}
                                                            className={cn(
                                                                'rounded-xl border px-5 py-6 text-left transition-all',
                                                                form.data
                                                                    .model_tier ===
                                                                    tier.value &&
                                                                    !form.data
                                                                        .model_primary
                                                                    ? 'border-foreground bg-accent shadow-sm'
                                                                    : 'border-border hover:border-foreground/30',
                                                            )}
                                                        >
                                                            <div className="mb-2 text-2xl">
                                                                {tier.value ===
                                                                'efficient'
                                                                    ? '\u26A1'
                                                                    : '\uD83E\uDDE0'}
                                                            </div>
                                                            <p className="text-base font-bold">
                                                                {tier.label}
                                                            </p>
                                                            <p className="mt-1 text-sm text-muted-foreground">
                                                                {
                                                                    tier.description
                                                                }
                                                            </p>
                                                            <p className="mt-3 text-xs font-medium text-muted-foreground">
                                                                {tier.cost} in
                                                                AI costs
                                                            </p>
                                                        </button>
                                                    ))}
                                                </div>

                                                {availableModels.length > 0 && (
                                                    <button
                                                        type="button"
                                                        onClick={() =>
                                                            setShowAdvancedModel(
                                                                true,
                                                            )
                                                        }
                                                        className="text-xs text-muted-foreground underline transition-colors hover:text-foreground"
                                                    >
                                                        Advanced: choose a
                                                        specific model
                                                    </button>
                                                )}
                                            </>
                                        ) : (
                                            <>
                                                {availableModels.length > 0 ? (
                                                    <div className="w-full">
                                                        {/* Group by tier */}
                                                        {(
                                                            [
                                                                'pro',
                                                                'standard',
                                                                'lite',
                                                            ] as const
                                                        ).map((tier) => {
                                                            const tierModels = [
                                                                ...availableModels,
                                                            ]
                                                                .filter(
                                                                    (m) =>
                                                                        modelMeta[
                                                                            m
                                                                                .value
                                                                        ]
                                                                            ?.tier ===
                                                                        tier,
                                                                )
                                                                .sort(
                                                                    (a, b) =>
                                                                        (modelMeta[
                                                                            a
                                                                                .value
                                                                        ]
                                                                            ?.sort ??
                                                                            99) -
                                                                        (modelMeta[
                                                                            b
                                                                                .value
                                                                        ]
                                                                            ?.sort ??
                                                                            99),
                                                                );
                                                            if (
                                                                tierModels.length ===
                                                                0
                                                            )
                                                                return null;
                                                            const cfg =
                                                                tierConfig[
                                                                    tier
                                                                ];
                                                            return (
                                                                <div
                                                                    key={tier}
                                                                    className="mt-4 first:mt-0"
                                                                >
                                                                    <div className="mb-2 flex items-center gap-2">
                                                                        <span
                                                                            className={cn(
                                                                                'rounded px-1.5 py-0.5 text-[10px] font-semibold',
                                                                                cfg.className,
                                                                            )}
                                                                        >
                                                                            {
                                                                                cfg.label
                                                                            }
                                                                        </span>
                                                                        <span className="text-[11px] text-muted-foreground">
                                                                            {tier ===
                                                                            'pro'
                                                                                ? 'Most capable'
                                                                                : tier ===
                                                                                    'standard'
                                                                                  ? 'Best balance'
                                                                                  : 'Budget-friendly'}
                                                                        </span>
                                                                    </div>
                                                                    <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                                                                        {tierModels.map(
                                                                            (
                                                                                model,
                                                                            ) => {
                                                                                const meta =
                                                                                    modelMeta[
                                                                                        model
                                                                                            .value
                                                                                    ];
                                                                                const isSelected =
                                                                                    form
                                                                                        .data
                                                                                        .model_primary ===
                                                                                    model.value;
                                                                                return (
                                                                                    <button
                                                                                        key={
                                                                                            model.value
                                                                                        }
                                                                                        type="button"
                                                                                        onClick={() => {
                                                                                            form.setData(
                                                                                                'model_primary',
                                                                                                model.value,
                                                                                            );
                                                                                            form.setData(
                                                                                                'model_tier',
                                                                                                '',
                                                                                            );
                                                                                        }}
                                                                                        className={cn(
                                                                                            'rounded-lg border px-3 py-2.5 text-left transition-all',
                                                                                            isSelected
                                                                                                ? 'border-foreground bg-accent shadow-sm'
                                                                                                : 'border-border hover:border-foreground/30',
                                                                                        )}
                                                                                    >
                                                                                        <p className="text-[13px] leading-tight font-medium">
                                                                                            {meta?.label ??
                                                                                                model.label}
                                                                                        </p>
                                                                                        <p className="mt-0.5 text-[11px] leading-tight text-muted-foreground">
                                                                                            {meta?.description ??
                                                                                                model.provider}
                                                                                        </p>
                                                                                    </button>
                                                                                );
                                                                            },
                                                                        )}
                                                                    </div>
                                                                </div>
                                                            );
                                                        })}

                                                        {/* Ungrouped models (no meta) */}
                                                        {(() => {
                                                            const ungrouped =
                                                                availableModels.filter(
                                                                    (m) =>
                                                                        !modelMeta[
                                                                            m
                                                                                .value
                                                                        ],
                                                                );
                                                            if (
                                                                ungrouped.length ===
                                                                0
                                                            )
                                                                return null;
                                                            return (
                                                                <div className="mt-4">
                                                                    <div className="mb-2 text-[11px] text-muted-foreground">
                                                                        Other
                                                                    </div>
                                                                    <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                                                                        {ungrouped.map(
                                                                            (
                                                                                model,
                                                                            ) => {
                                                                                const isSelected =
                                                                                    form
                                                                                        .data
                                                                                        .model_primary ===
                                                                                    model.value;
                                                                                return (
                                                                                    <button
                                                                                        key={
                                                                                            model.value
                                                                                        }
                                                                                        type="button"
                                                                                        onClick={() => {
                                                                                            form.setData(
                                                                                                'model_primary',
                                                                                                model.value,
                                                                                            );
                                                                                            form.setData(
                                                                                                'model_tier',
                                                                                                '',
                                                                                            );
                                                                                        }}
                                                                                        className={cn(
                                                                                            'rounded-lg border px-3 py-2.5 text-left transition-all',
                                                                                            isSelected
                                                                                                ? 'border-foreground bg-accent shadow-sm'
                                                                                                : 'border-border hover:border-foreground/30',
                                                                                        )}
                                                                                    >
                                                                                        <p className="text-[13px] leading-tight font-medium">
                                                                                            {
                                                                                                model.label
                                                                                            }
                                                                                        </p>
                                                                                        <p className="mt-0.5 text-[11px] leading-tight text-muted-foreground">
                                                                                            {
                                                                                                model.provider
                                                                                            }
                                                                                        </p>
                                                                                    </button>
                                                                                );
                                                                            },
                                                                        )}
                                                                    </div>
                                                                </div>
                                                            );
                                                        })()}
                                                    </div>
                                                ) : (
                                                    <div className="w-full rounded-lg border border-yellow-200 bg-yellow-50 p-4 dark:border-yellow-900 dark:bg-yellow-950">
                                                        <p className="text-sm text-yellow-800 dark:text-yellow-200">
                                                            No models available.{' '}
                                                            <Link
                                                                href={`/settings/teams/${auth.user.current_team_id}/api-keys`}
                                                                className="font-medium underline"
                                                            >
                                                                Add an API key
                                                            </Link>{' '}
                                                            to unlock models.
                                                        </p>
                                                    </div>
                                                )}

                                                <button
                                                    type="button"
                                                    onClick={() => {
                                                        setShowAdvancedModel(
                                                            false,
                                                        );
                                                        form.setData(
                                                            'model_primary',
                                                            '',
                                                        );
                                                        form.setData(
                                                            'model_tier',
                                                            defaultTier,
                                                        );
                                                    }}
                                                    className="text-xs text-muted-foreground underline transition-colors hover:text-foreground"
                                                >
                                                    Back to simple selection
                                                </button>
                                            </>
                                        )}

                                        {needsSeat && (
                                            <div className="w-full rounded-lg border border-border bg-muted/50 p-4">
                                                {isOnTrial ? (
                                                    <>
                                                        <p className="text-sm text-foreground">
                                                            This adds a{' '}
                                                            <span className="font-semibold">
                                                                $
                                                                {
                                                                    seatPriceMonthly
                                                                }
                                                                /mo
                                                            </span>{' '}
                                                            agent seat. Your
                                                            trial continues — no
                                                            charge until{' '}
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
                                                        <p className="mt-1 text-xs text-muted-foreground">
                                                            After trial: $
                                                            {(
                                                                planPriceCents /
                                                                100
                                                            ).toFixed(0)}
                                                            /mo plan +{' '}
                                                            {extraSeats + 1}{' '}
                                                            seat
                                                            {extraSeats + 1 !==
                                                            1
                                                                ? 's'
                                                                : ''}{' '}
                                                            ($
                                                            {(
                                                                (extraSeats +
                                                                    1) *
                                                                parseInt(
                                                                    seatPriceMonthly,
                                                                )
                                                            ).toFixed(0)}
                                                            /mo) ={' '}
                                                            <span className="font-semibold text-foreground">
                                                                $
                                                                {(
                                                                    planPriceCents /
                                                                        100 +
                                                                    (extraSeats +
                                                                        1) *
                                                                        parseInt(
                                                                            seatPriceMonthly,
                                                                        )
                                                                ).toFixed(0)}
                                                                /mo total
                                                            </span>
                                                        </p>
                                                    </>
                                                ) : (
                                                    <>
                                                        <p className="text-sm text-foreground">
                                                            This agent will add
                                                            a{' '}
                                                            <span className="font-semibold">
                                                                $
                                                                {
                                                                    seatPriceMonthly
                                                                }
                                                                /mo
                                                            </span>{' '}
                                                            agent seat to your
                                                            subscription.
                                                        </p>
                                                        <p className="mt-1 text-xs text-muted-foreground">
                                                            You can remove seats
                                                            anytime from billing
                                                            settings.
                                                        </p>
                                                    </>
                                                )}
                                            </div>
                                        )}

                                        {Object.keys(form.errors).length >
                                            0 && (
                                            <div className="w-full rounded-lg border border-destructive/50 bg-destructive/10 p-3">
                                                {Object.entries(
                                                    form.errors,
                                                ).map(([key, msg]) => (
                                                    <p
                                                        key={key}
                                                        className="text-sm text-destructive"
                                                    >
                                                        {msg}
                                                    </p>
                                                ))}
                                            </div>
                                        )}

                                        <div className="flex w-full items-center justify-between">
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                onClick={back}
                                            >
                                                Back
                                            </Button>
                                            <Button
                                                type="submit"
                                                disabled={
                                                    form.processing ||
                                                    (!form.data.model_tier &&
                                                        !form.data
                                                            .model_primary)
                                                }
                                            >
                                                {form.processing
                                                    ? 'Creating...'
                                                    : needsSeat
                                                      ? `Create ${form.data.name} — $${seatPriceMonthly}/mo`
                                                      : `Create ${form.data.name}`}
                                            </Button>
                                        </div>
                                    </form>
                                )}
                            </StepTransition>
                        </div>
                    </div>
                </div>

                {/* Right Column - Badge Preview (Hidden on small screens) */}
                <div className="relative hidden w-[480px] shrink-0 flex-col items-center border-l border-border/40 bg-muted/5 pt-28 lg:flex">
                    {/* Grid background pattern */}
                    <div className="pointer-events-none absolute inset-0 bg-[linear-gradient(to_right,#8080800a_1px,transparent_1px),linear-gradient(to_bottom,#8080800a_1px,transparent_1px)] bg-[size:32px_32px]" />

                    <BadgePreview
                        name={form.data.name}
                        emoji={form.data.emoji}
                        tone={form.data.communication_style}
                        trait={form.data.personality}
                        model={
                            form.data.model_primary ||
                            (form.data.model_tier
                                ? (modelTiers.find(
                                      (t) => t.value === form.data.model_tier,
                                  )?.label ?? '')
                                : '')
                        }
                        harness={form.data.harness_type}
                        emailPrefix={form.data.email_prefix}
                        emailDomain={emailDomain}
                        stepIndex={stepIndex}
                    />
                </div>
            </div>
        </div>
    );
}

function OptionStep({
    heading,
    subtext,
    options,
    selected,
    onSelect,
    onBack,
    onNext,
}: {
    heading: string;
    subtext?: string;
    options: { value: string; label: string; description: string }[];
    selected: string;
    onSelect: (value: string) => void;
    onBack: () => void;
    onNext: () => void;
}) {
    return (
        <div className="flex flex-col items-center gap-6">
            <div className="text-center">
                <h1 className="text-2xl font-bold tracking-tight">{heading}</h1>
                {subtext && (
                    <p className="mt-2 text-sm text-muted-foreground">
                        {subtext}
                    </p>
                )}
            </div>

            <div className="grid w-full grid-cols-2 gap-3">
                {options.map((option) => (
                    <button
                        key={option.value}
                        type="button"
                        onClick={() => onSelect(option.value)}
                        className={cn(
                            'rounded-xl border px-4 py-4 text-left transition-all',
                            selected === option.value
                                ? 'border-foreground bg-accent shadow-sm'
                                : 'border-border hover:border-foreground/30',
                        )}
                    >
                        <p className="text-sm font-medium">{option.label}</p>
                        <p className="mt-0.5 text-xs text-muted-foreground">
                            {option.description}
                        </p>
                    </button>
                ))}
            </div>

            <NavButtons onBack={onBack} onNext={onNext} />
        </div>
    );
}

function NavButtons({
    onBack,
    onNext,
}: {
    onBack: () => void;
    onNext: () => void;
}) {
    return (
        <div className="flex w-full items-center justify-between">
            <Button variant="ghost" onClick={onBack}>
                Back
            </Button>
            <Button onClick={onNext}>Continue</Button>
        </div>
    );
}

function SkipNavButtons({
    onBack,
    onNext,
}: {
    onBack: () => void;
    onNext: () => void;
}) {
    return (
        <div className="flex w-full items-center justify-between">
            <Button variant="ghost" onClick={onBack}>
                Back
            </Button>
            <div className="flex gap-2">
                <Button variant="outline" onClick={onNext}>
                    Skip
                </Button>
                <Button onClick={onNext}>Continue</Button>
            </div>
        </div>
    );
}
