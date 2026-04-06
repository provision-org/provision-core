import { Head, router } from '@inertiajs/react';
import {
    AlertCircle,
    ArrowRight,
    Bot,
    Check,
    Clock,
    Crown,
    Globe,
    Mail,
    MessageSquare,
    Shield,
    Sparkles,
    Users,
    Zap,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import { cn } from '@/lib/utils';

type PlanInfo = {
    key: string;
    label: string;
    price_cents: number;
    included_agents: number;
    max_agents: number;
    included_credits_cents: number;
    agent_seat_price_cents: number;
    storage_mb: number;
    trial_days: number;
};

const PLAN_FEATURES: Record<string, string[]> = {
    pro: [
        'Up to 4 AI agents',
        '50 MB workspace per agent',
        'Slack, Telegram & Discord',
        'Email tool for every agent',
        'Full agent customization',
    ],
};

const CAPABILITIES = [
    {
        icon: <MessageSquare className="size-4" />,
        title: 'Live in your channels',
        desc: 'Agents join Slack, Telegram, and Discord as real team members.',
    },
    {
        icon: <Mail className="size-4" />,
        title: 'Their own email',
        desc: 'Every agent gets a dedicated email address they act on autonomously.',
    },
    {
        icon: <Globe className="size-4" />,
        title: 'Browse the web',
        desc: 'Research, monitor competitors, gather leads from any public site.',
    },
    {
        icon: <Clock className="size-4" />,
        title: 'Work 24/7',
        desc: 'No breaks, no PTO. Continuous operation on dedicated infrastructure.',
    },
    {
        icon: <Bot className="size-4" />,
        title: 'Frontier models',
        desc: 'Claude, GPT-4, and more with automatic fallbacks.',
    },
    {
        icon: <Shield className="size-4" />,
        title: 'Secure & isolated',
        desc: 'Each team gets its own server. Your data stays yours.',
    },
];

function GradientOrb() {
    const ref = useRef<HTMLDivElement>(null);

    useEffect(() => {
        let frame: number;
        let t = 0;
        const animate = () => {
            t += 0.003;
            if (ref.current) {
                const x = 50 + Math.sin(t) * 15;
                const y = 30 + Math.cos(t * 0.7) * 10;
                ref.current.style.background = `radial-gradient(ellipse at ${x}% ${y}%, oklch(0.45 0.18 275 / 0.15) 0%, oklch(0.35 0.12 260 / 0.06) 40%, transparent 70%)`;
            }
            frame = requestAnimationFrame(animate);
        };
        animate();
        return () => cancelAnimationFrame(frame);
    }, []);

    return <div ref={ref} className="pointer-events-none absolute inset-0" />;
}

function FadeIn({
    children,
    delay = 0,
    className = '',
}: {
    children: React.ReactNode;
    delay?: number;
    className?: string;
}) {
    return (
        <div
            className={cn('animate-in fade-in slide-in-from-bottom-4 fill-mode-both', className)}
            style={{ animationDelay: `${delay}ms`, animationDuration: '600ms' }}
        >
            {children}
        </div>
    );
}

export default function Subscribe({
    plan,
    teamName,
}: {
    plan: PlanInfo;
    teamName: string;
}) {
    const [processing, setProcessing] = useState(false);
    const checkoutCancelled =
        typeof window !== 'undefined' &&
        new URLSearchParams(window.location.search).get('checkout') === 'cancelled';

    const handleSubscribe = () => {
        setProcessing(true);
        router.post(
            '/billing/subscribe',
            { plan: plan.key },
            { onError: () => setProcessing(false) },
        );
    };

    const features = PLAN_FEATURES[plan.key] ?? [
        `Up to ${plan.max_agents} AI agents`,
        `${plan.storage_mb} MB workspace per agent`,
        'Slack, Telegram & Discord',
        'Email tool for every agent',
        'Full agent customization',
    ];

    return (
        <>
            <Head title="Choose your plan" />

            <div className="relative flex min-h-svh flex-col items-center overflow-hidden bg-background px-4 py-14 sm:px-6">
                <GradientOrb />

                <FadeIn delay={0}>
                    <div className="mb-12 flex items-center gap-2.5">
                        <AppLogoIcon className="size-8 fill-current text-foreground/80" />
                        <span className="text-sm font-semibold tracking-wide text-foreground/40 uppercase">
                            Provision
                        </span>
                    </div>
                </FadeIn>

                {checkoutCancelled && (
                    <FadeIn className="w-full max-w-md">
                        <div className="mb-6 flex items-center gap-2 rounded-lg border border-destructive/50 bg-destructive/10 p-3">
                            <AlertCircle className="size-4 shrink-0 text-destructive" />
                            <p className="text-sm text-destructive">
                                Checkout was cancelled. Please try again.
                            </p>
                        </div>
                    </FadeIn>
                )}

                <FadeIn delay={80}>
                    <div className="mb-3 text-center">
                        <h1 className="text-3xl font-bold tracking-tight sm:text-4xl">
                            Choose a plan for{' '}
                            <span className="bg-gradient-to-r from-primary to-indigo-400 bg-clip-text text-transparent">
                                {teamName}
                            </span>
                        </h1>
                    </div>
                </FadeIn>

                <FadeIn delay={140}>
                    <p className="mb-8 text-center text-muted-foreground">
                        Start your free trial today. No charge until it ends.
                    </p>
                </FadeIn>

                <FadeIn delay={200}>
                    <div className="mb-8 inline-flex items-center gap-2 rounded-full border border-primary/20 bg-primary/[0.06] px-4 py-2 backdrop-blur-sm">
                        <Sparkles className="size-3.5 text-primary" />
                        <span className="text-xs font-medium text-foreground/80">
                            Teams save 20+ hours/week on repetitive work
                        </span>
                    </div>
                </FadeIn>

                <FadeIn delay={300} className="w-full max-w-md">
                    <div className="group relative">
                        <div className="relative flex flex-col rounded-2xl border border-primary/30 bg-card/80 p-6 backdrop-blur-sm transition-all duration-300 hover:-translate-y-0.5 hover:border-primary/50">
                            <span className="absolute -top-3 left-5 rounded-full bg-gradient-to-r from-primary to-indigo-400 px-3 py-0.5 text-[11px] font-semibold text-white shadow-sm">
                                {plan.trial_days}-day free trial
                            </span>

                            <div className="mb-4 flex items-center gap-2.5">
                                <div className="flex size-9 items-center justify-center rounded-lg bg-gradient-to-br from-primary/80 to-primary text-white shadow-sm">
                                    <Crown className="size-5" />
                                </div>
                                <span className="text-lg font-bold">{plan.label}</span>
                            </div>

                            <div className="mb-5">
                                <span className="text-4xl font-extrabold tracking-tight">
                                    ${(plan.price_cents / 100).toFixed(0)}
                                </span>
                                <span className="ml-1 text-sm text-muted-foreground">
                                    /month
                                </span>
                            </div>

                            <ul className="mb-6 flex-1 space-y-3">
                                {features.map((feature) => (
                                    <li
                                        key={feature}
                                        className="flex items-start gap-2.5 text-sm"
                                    >
                                        <div className="mt-0.5 flex size-4 shrink-0 items-center justify-center rounded-full bg-primary/15">
                                            <Check className="size-2.5 text-primary" />
                                        </div>
                                        <span className="text-foreground/80">{feature}</span>
                                    </li>
                                ))}
                            </ul>

                            <button
                                onClick={handleSubscribe}
                                disabled={processing}
                                className="flex h-11 w-full items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-primary to-indigo-500 text-sm font-semibold text-white shadow-md shadow-primary/20 transition-all duration-200 hover:shadow-lg hover:shadow-primary/30 hover:brightness-110 disabled:pointer-events-none disabled:opacity-50"
                            >
                                {processing ? (
                                    'Redirecting...'
                                ) : (
                                    <>
                                        Start {plan.trial_days}-day free trial
                                        <ArrowRight className="size-3.5 transition-transform group-hover:translate-x-0.5" />
                                    </>
                                )}
                            </button>
                        </div>
                    </div>
                </FadeIn>

                <FadeIn delay={400}>
                    <div className="mt-6 flex items-center gap-5 text-xs text-muted-foreground">
                        <span className="flex items-center gap-1.5">
                            <Zap className="size-3 text-primary/60" />
                            Free trial included
                        </span>
                        <span className="h-3 w-px bg-border" />
                        <span className="flex items-center gap-1.5">
                            <Shield className="size-3 text-primary/60" />
                            Cancel anytime
                        </span>
                    </div>
                </FadeIn>

                <FadeIn delay={450}>
                    <div className="my-12 flex w-full max-w-2xl items-center gap-4">
                        <div className="h-px flex-1 bg-gradient-to-r from-transparent to-border" />
                        <span className="text-[10px] font-medium tracking-widest text-muted-foreground/50 uppercase">
                            What you get
                        </span>
                        <div className="h-px flex-1 bg-gradient-to-l from-transparent to-border" />
                    </div>
                </FadeIn>

                <FadeIn delay={500}>
                    <div className="mb-8 text-center">
                        <h2 className="text-xl font-bold tracking-tight">
                            Not assistants. Not copilots.{' '}
                            <span className="bg-gradient-to-r from-primary to-indigo-400 bg-clip-text text-transparent">
                                Workers.
                            </span>
                        </h2>
                        <p className="mt-2 text-sm text-muted-foreground">
                            Every agent is a full team member with their own identity, tools, and
                            always-on runtime.
                        </p>
                    </div>
                </FadeIn>

                <FadeIn delay={560} className="w-full max-w-2xl">
                    <div className="grid gap-3 sm:grid-cols-2 md:grid-cols-3">
                        {CAPABILITIES.map((cap) => (
                            <div
                                key={cap.title}
                                className="group/cap flex flex-col gap-2.5 rounded-xl border border-border/50 bg-card/40 p-4 backdrop-blur-sm transition-all duration-300 hover:border-primary/30 hover:bg-card/70"
                            >
                                <div className="flex size-8 items-center justify-center rounded-lg bg-primary/10 text-primary transition-colors duration-300 group-hover/cap:bg-primary/20">
                                    {cap.icon}
                                </div>
                                <div>
                                    <p className="text-[13px] font-semibold">{cap.title}</p>
                                    <p className="mt-0.5 text-xs leading-relaxed text-muted-foreground">
                                        {cap.desc}
                                    </p>
                                </div>
                            </div>
                        ))}
                    </div>
                </FadeIn>

                <FadeIn delay={620}>
                    <p className="mt-10 text-center text-xs text-muted-foreground/60">
                        While you're deciding, your competitors are already deploying AI workers.
                    </p>
                </FadeIn>

                <FadeIn delay={650}>
                    <p className="mt-3 max-w-md text-center text-[11px] text-muted-foreground/40">
                        Your card will only be charged after the trial period ends. Cancel or change
                        plans anytime from billing settings.
                    </p>
                </FadeIn>
            </div>
        </>
    );
}
