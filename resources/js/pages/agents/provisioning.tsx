import { Head, router, usePoll } from '@inertiajs/react';
import { AlertCircle, CheckCircle2, Loader2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import { cn } from '@/lib/utils';
import type { AgentStatus } from '@/types';

type Props = {
    agent: { id: number; name: string; status: AgentStatus };
};

const steps = [
    { key: 'configuring', label: 'Configuring agent' },
    { key: 'workspace', label: 'Setting up workspace' },
    { key: 'starting', label: 'Starting services' },
    { key: 'live', label: 'Agent is live' },
] as const;

export default function AgentProvisioning({ agent }: Props) {
    const isError = agent.status === 'error';
    const isActive = agent.status === 'active';
    const isDeploying = agent.status === 'deploying';

    const [simulatedStep, setSimulatedStep] = useState(0);
    const timerRef = useRef<ReturnType<typeof setInterval> | null>(null);

    const { stop } = usePoll(
        3000,
        { only: ['agent'] },
        { autoStart: !isActive && !isError },
    );

    // Simulated step progression while deploying
    useEffect(() => {
        if (isDeploying && !timerRef.current) {
            timerRef.current = setInterval(() => {
                setSimulatedStep((prev) =>
                    Math.min(prev + 1, steps.length - 2),
                );
            }, 4000);
        }

        if (!isDeploying && timerRef.current) {
            clearInterval(timerRef.current);
            timerRef.current = null;
        }

        return () => {
            if (timerRef.current) {
                clearInterval(timerRef.current);
            }
        };
    }, [isDeploying]);

    // Redirect to show page when active
    useEffect(() => {
        if (isActive) {
            stop();

            // Show final step immediately, then redirect
            const stepTimeout = setTimeout(
                () => setSimulatedStep(steps.length - 1),
                0,
            );
            const redirectTimeout = setTimeout(() => {
                router.visit(`/agents/${agent.id}`);
            }, 800);

            return () => {
                clearTimeout(stepTimeout);
                clearTimeout(redirectTimeout);
            };
        }
    }, [isActive, stop, agent.id]);

    const activeIndex = isActive ? steps.length - 1 : simulatedStep;

    return (
        <div className="flex min-h-svh flex-col items-center justify-center gap-6 bg-background p-6 md:p-10">
            <Head title={`Deploying ${agent.name}`} />

            <div className="w-full max-w-sm">
                <div className="flex flex-col items-center gap-8">
                    <div className="flex flex-col items-center gap-4">
                        <div className="mb-1 flex h-9 w-9 items-center justify-center rounded-md">
                            <AppLogoIcon className="size-9 fill-current text-[var(--foreground)] dark:text-white" />
                        </div>

                        <div className="space-y-2 text-center">
                            <h1 className="text-xl font-medium">
                                Deploying {agent.name}
                            </h1>
                            <p className="text-sm text-muted-foreground">
                                We&apos;re setting up your agent. This usually
                                takes a minute or two.
                            </p>
                        </div>
                    </div>

                    {isError ? (
                        <div className="w-full rounded-lg border border-destructive/50 bg-destructive/10 p-4">
                            <div className="flex items-center gap-3">
                                <AlertCircle className="size-5 shrink-0 text-destructive" />
                                <div>
                                    <p className="text-sm font-medium text-destructive">
                                        Agent deployment failed
                                    </p>
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        Something went wrong while deploying
                                        your agent.{' '}
                                        <a
                                            href={`/agents/${agent.id}`}
                                            className="underline hover:text-foreground"
                                        >
                                            Go to agent page
                                        </a>
                                    </p>
                                </div>
                            </div>
                        </div>
                    ) : (
                        <div className="w-full space-y-3">
                            {steps.map((step, index) => {
                                const isCompleted = isActive
                                    ? true
                                    : index < activeIndex;
                                const isCurrent =
                                    !isActive && index === activeIndex;

                                return (
                                    <div
                                        key={step.key}
                                        className={cn(
                                            'flex items-center gap-3 rounded-lg border px-4 py-3 transition-all duration-300',
                                            isCompleted &&
                                                'border-emerald-500/30 bg-emerald-500/5',
                                            isCurrent &&
                                                'border-foreground/20 bg-accent',
                                            !isCompleted &&
                                                !isCurrent &&
                                                'border-transparent opacity-40',
                                        )}
                                    >
                                        {isCompleted ? (
                                            <CheckCircle2 className="size-5 shrink-0 text-emerald-500" />
                                        ) : isCurrent ? (
                                            <Loader2 className="size-5 shrink-0 animate-spin text-foreground" />
                                        ) : (
                                            <div className="size-5 shrink-0 rounded-full border-2 border-muted-foreground/30" />
                                        )}
                                        <span
                                            className={cn(
                                                'text-sm',
                                                isCompleted &&
                                                    'text-emerald-600 dark:text-emerald-400',
                                                isCurrent &&
                                                    'font-medium text-foreground',
                                                !isCompleted &&
                                                    !isCurrent &&
                                                    'text-muted-foreground',
                                            )}
                                        >
                                            {step.label}
                                        </span>
                                    </div>
                                );
                            })}
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}
