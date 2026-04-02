import { Head, router, usePoll } from '@inertiajs/react';
import { CheckCircle2, AlertCircle, ChevronDown, Loader2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import { cn } from '@/lib/utils';
import type { ServerStatus } from '@/types';

type ServerEvent = {
    id: string;
    event: string;
    step: string | null;
    created_at: string;
};

type Props = {
    team: { id: number; name: string };
    server: {
        id: number;
        status: ServerStatus;
        events: ServerEvent[];
    } | null;
};

const steps = [
    { key: 'provisioning', label: 'Creating server' },
    { key: 'setup_complete', label: 'Installing dependencies' },
    { key: 'configuring', label: 'Configuring environment' },
    { key: 'running', label: 'Ready to go' },
] as const;

const stepLabels: Record<string, string> = {
    mounting_volume: 'Mounting persistent storage',
    installing_packages: 'Installing system packages',
    installing_github_cli: 'Installing GitHub',
    installing_chrome: 'Installing browser',
    installing_vnc: 'Installing remote desktop',
    installing_caddy: 'Configuring network routing',
    installing_openclaw: 'Installing OpenClaw',
    installing_hermes: 'Installing Hermes Agent',
    configuring_firewall: 'Configuring firewall',
    onboarding: 'Running OpenClaw onboarding',
    configuring_browser: 'Configuring headless browser',
    configuring_defaults: 'Configuring agent defaults',
    installing_advanced_memory: 'Installing advanced memory',
    installing_gateway: 'Installing gateway service',
    starting_services: 'Starting services',
    finalizing: 'Finalizing setup',
};

const setupSteps = new Set([
    'onboarding',
    'configuring_browser',
    'configuring_defaults',
    'installing_advanced_memory',
    'installing_gateway',
    'starting_services',
    'finalizing',
]);

// Cloud-init steps in expected order (for granular progress)
const cloudInitSteps = [
    'mounting_volume',
    'installing_packages',
    'installing_github_cli',
    'installing_chrome',
    'installing_vnc',
    'installing_caddy',
    'installing_openclaw',
    'installing_hermes',
    'configuring_firewall',
];

function getActiveIndex(
    status: ServerStatus | undefined,
    events: ServerEvent[],
): number {
    if (!status) {
        return 0;
    }

    if (status === 'running') {
        return 3;
    }

    // Check if we have setup_progress events (means we're in configuring phase)
    const hasSetupProgress = events.some(
        (e) => e.event === 'setup_progress' && e.step && setupSteps.has(e.step),
    );
    if (hasSetupProgress) {
        return 2;
    }

    // Check if server_ready event exists (means cloud-init done, setup starting)
    const hasServerReady = events.some((e) => e.event === 'server_ready');
    if (hasServerReady || status === 'setup_complete') {
        return 1;
    }

    return 0;
}

function getProgressPercent(
    activeIndex: number,
    events: ServerEvent[],
): number {
    // Phase 3 (running) = 100%
    if (activeIndex >= 3) return 100;

    // Phase 2 (configuring) = 50-90% based on setup steps completed
    if (activeIndex === 2) {
        const setupEvents = events.filter(
            (e) => e.event === 'setup_progress' && e.step && setupSteps.has(e.step),
        );
        const setupProgress = Math.min(setupEvents.length / 7, 1); // 7 setup steps
        return Math.round(50 + setupProgress * 40); // 50% → 90%
    }

    // Phase 1 (cloud-init done, waiting for setup) = 45%
    if (activeIndex === 1) return 45;

    // Phase 0 (cloud-init running) = 5-45% based on cloud-init steps completed
    const cloudInitEvents = events.filter(
        (e) => e.event === 'cloud_init_progress' && e.step,
    );
    if (cloudInitEvents.length === 0) return 5;

    const completedCloudInit = cloudInitEvents.length;
    const totalCloudInit = cloudInitSteps.length;
    const cloudInitProgress = Math.min(completedCloudInit / totalCloudInit, 1);
    return Math.round(5 + cloudInitProgress * 40); // 5% → 45%
}

function ActivityFeed({ events }: { events: ServerEvent[] }) {
    const [expanded, setExpanded] = useState(false);

    const feedEvents = events.filter(
        (e) =>
            (e.event === 'cloud_init_progress' ||
                e.event === 'setup_progress') &&
            e.step,
    );

    if (feedEvents.length === 0) {
        return null;
    }

    const latestEvent = feedEvents[feedEvents.length - 1];
    const latestLabel = stepLabels[latestEvent.step!] ?? latestEvent.step;
    const pastEvents = feedEvents.slice(0, -1);

    return (
        <div className="space-y-1">
            <div className="flex items-center gap-2 pl-0.5">
                <Loader2 className="size-3 shrink-0 animate-spin text-muted-foreground" />
                <span className="text-xs text-muted-foreground">
                    {latestLabel}
                </span>
            </div>
            {pastEvents.length > 0 && (
                <button
                    type="button"
                    onClick={() => setExpanded(!expanded)}
                    className="flex items-center gap-1 pl-0.5 text-xs text-muted-foreground/60 hover:text-muted-foreground"
                >
                    <ChevronDown
                        className={cn(
                            'size-3 shrink-0 transition-transform duration-200',
                            expanded && 'rotate-180',
                        )}
                    />
                    <span>{pastEvents.length} completed</span>
                </button>
            )}
            {expanded && (
                <div className="space-y-0.5 pl-0.5">
                    {pastEvents.map((event) => {
                        const label = stepLabels[event.step!] ?? event.step;

                        return (
                            <div
                                key={event.id}
                                className="flex items-center gap-2 py-0.5"
                            >
                                <CheckCircle2 className="size-3 shrink-0 text-emerald-500/60" />
                                <span className="text-xs text-muted-foreground/60">
                                    {label}
                                </span>
                            </div>
                        );
                    })}
                </div>
            )}
        </div>
    );
}

export default function Provisioning({ team, server }: Props) {
    const isError = server?.status === 'error';
    const isRunning = server?.status === 'running';
    const events = server?.events ?? [];
    const activeIndex = getActiveIndex(server?.status, events);

    const { stop } = usePoll(
        3000,
        { only: ['server'] },
        { autoStart: !isRunning && !isError },
    );

    useEffect(() => {
        if (isRunning) {
            stop();
            router.visit('/agents');
        }
    }, [isRunning, stop]);

    const progressPercent = getProgressPercent(activeIndex, events);
    const currentStep = steps[activeIndex];
    const completedSteps = steps.filter((_, i) => i < activeIndex);

    return (
        <div className="flex min-h-svh flex-col items-center justify-center gap-6 bg-background p-6 md:p-10">
            <Head title="Setting up your server" />

            <div className="w-full max-w-sm">
                <div className="flex flex-col items-center gap-8">
                    <div className="flex flex-col items-center gap-4">
                        <div className="mb-1 flex h-9 w-9 items-center justify-center rounded-md">
                            <AppLogoIcon className="size-9 fill-current text-[var(--foreground)] dark:text-white" />
                        </div>

                        <div className="space-y-2 text-center">
                            <h1 className="text-xl font-medium">
                                Setting up {team.name}
                            </h1>
                            <p className="text-sm text-muted-foreground">
                                We&apos;re provisioning your server. This
                                usually takes a few minutes.
                            </p>
                        </div>
                    </div>

                    {isError ? (
                        <div className="w-full rounded-lg border border-destructive/50 bg-destructive/10 p-4">
                            <div className="flex items-center gap-3">
                                <AlertCircle className="size-5 shrink-0 text-destructive" />
                                <div>
                                    <p className="text-sm font-medium text-destructive">
                                        Server provisioning failed
                                    </p>
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        Something went wrong while setting up
                                        your server.{' '}
                                        <a
                                            href={`/settings/teams/${team.id}`}
                                            className="underline hover:text-foreground"
                                        >
                                            Go to team settings
                                        </a>
                                    </p>
                                </div>
                            </div>
                        </div>
                    ) : (
                        <div className="w-full space-y-4 rounded-xl border bg-card p-5">
                            {/* Progress bar */}
                            <div className="space-y-2">
                                <div className="flex items-center justify-between text-sm">
                                    <span className="text-muted-foreground">
                                        Progress
                                    </span>
                                    <span className="font-medium tabular-nums">
                                        {progressPercent}%
                                    </span>
                                </div>
                                <div className="h-2 w-full overflow-hidden rounded-full bg-muted">
                                    <div
                                        className="h-full rounded-full bg-emerald-500 transition-all duration-700 ease-out"
                                        style={{ width: `${progressPercent}%` }}
                                    />
                                </div>
                            </div>

                            {/* Current step */}
                            {currentStep && (
                                <div className="space-y-2">
                                    <div className="flex items-center gap-2">
                                        <Loader2 className="size-4 shrink-0 animate-spin text-foreground" />
                                        <span className="text-sm font-medium">
                                            {currentStep.label}
                                        </span>
                                    </div>
                                    <div className="pl-6">
                                        <ActivityFeed events={events} />
                                    </div>
                                </div>
                            )}

                            {/* Completed steps */}
                            {completedSteps.length > 0 && (
                                <>
                                    <div className="border-t" />
                                    <div className="space-y-1.5">
                                        {completedSteps.map((step) => (
                                            <div
                                                key={step.key}
                                                className="flex items-center gap-2"
                                            >
                                                <CheckCircle2 className="size-3.5 shrink-0 text-emerald-500" />
                                                <span className="text-xs text-muted-foreground">
                                                    {step.label}
                                                </span>
                                            </div>
                                        ))}
                                    </div>
                                </>
                            )}
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}
