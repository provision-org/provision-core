import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AuthLayout from '@/layouts/auth-layout';
import { cn } from '@/lib/utils';
import type { SharedData } from '@/types';

const harnessOptions = [
    {
        value: 'openclaw',
        label: 'OpenClaw',
        description:
            'Browser, email, Slack, and tools. Best for web research, data extraction, and multi-step workflows.',
    },
    {
        value: 'hermes',
        label: 'Hermes',
        description:
            'Reasoning and conversation. Best for analysis, writing, planning, and chat-based work. No browser.',
    },
];

type Step = 'name' | 'harness' | 'business' | 'provider';

export default function CreateTeam({
    harnessSelectionEnabled = false,
    cloudProviderSelectionEnabled = false,
    availableProviders = [],
    defaultProvider = 'docker',
}: {
    harnessSelectionEnabled?: boolean;
    cloudProviderSelectionEnabled?: boolean;
    availableProviders?: {
        value: string;
        label: string;
        description: string;
    }[];
    defaultProvider?: string;
}) {
    const { teams } = usePage<SharedData>().props;
    const hasExistingTeams = teams && teams.length > 0;
    const [step, setStep] = useState<Step>('name');

    const form = useForm({
        name: '',
        harness_type: 'openclaw',
        company_name: '',
        company_url: '',
        company_description: '',
        target_market: '',
        ...(cloudProviderSelectionEnabled
            ? { cloud_provider: defaultProvider }
            : {}),
    });

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        form.post('/settings/teams');
    }

    function nextStep() {
        if (step === 'name') {
            if (!form.data.name.trim()) return;
            setStep(harnessSelectionEnabled ? 'harness' : 'business');
        } else if (step === 'harness') {
            setStep('business');
        } else if (step === 'business') {
            if (cloudProviderSelectionEnabled) {
                setStep('provider');
            }
        }
    }

    function backStep() {
        if (step === 'harness') {
            setStep('name');
        } else if (step === 'business') {
            setStep(harnessSelectionEnabled ? 'harness' : 'name');
        } else if (step === 'provider') {
            setStep('business');
        }
    }

    const stepTitles: Record<Step, string> = {
        name: 'Create a team',
        harness: 'Choose your agent framework',
        business: `About ${form.data.name}`,
        provider: 'Where should your agents run?',
    };

    const stepDescriptions: Record<Step, string> = {
        name: 'Create a team to deploy your first AI employee.',
        harness: 'This determines how your agents run tasks.',
        business: 'Help your agents understand your business better.',
        provider: 'Choose the infrastructure for your agent servers.',
    };

    // Business step is the submit step when provider selection is disabled
    const isBusinessSubmitStep = !cloudProviderSelectionEnabled;

    return (
        <AuthLayout
            title={stepTitles[step]}
            description={stepDescriptions[step]}
        >
            <Head title="Create Team" />

            <form onSubmit={handleSubmit} className="space-y-6">
                {step === 'name' && (
                    <>
                        <div className="grid gap-2">
                            <Label htmlFor="name">Team name</Label>
                            <Input
                                id="name"
                                className="mt-1 block w-full"
                                name="name"
                                value={form.data.name}
                                onChange={(e) =>
                                    form.setData('name', e.target.value)
                                }
                                required
                                placeholder="e.g. Acme Sales"
                                autoFocus
                                onKeyDown={(e) => {
                                    if (e.key === 'Enter') {
                                        e.preventDefault();
                                        nextStep();
                                    }
                                }}
                            />
                            <InputError
                                className="mt-2"
                                message={form.errors.name}
                            />
                        </div>

                        <div className="flex flex-col gap-3">
                            <Button
                                type="button"
                                className="w-full"
                                onClick={nextStep}
                                disabled={!form.data.name.trim()}
                            >
                                Continue
                            </Button>

                            {hasExistingTeams && (
                                <Button
                                    variant="ghost"
                                    className="w-full"
                                    asChild
                                >
                                    <Link href="/agents">Cancel</Link>
                                </Button>
                            )}
                        </div>
                    </>
                )}

                {step === 'harness' && harnessSelectionEnabled && (
                    <>
                        <div className="grid gap-3">
                            {harnessOptions.map((option) => (
                                <button
                                    key={option.value}
                                    type="button"
                                    onClick={() =>
                                        form.setData(
                                            'harness_type',
                                            option.value,
                                        )
                                    }
                                    className={cn(
                                        'rounded-xl border px-4 py-4 text-left transition-all',
                                        form.data.harness_type === option.value
                                            ? 'border-foreground bg-accent shadow-sm'
                                            : 'border-border hover:border-foreground/30',
                                    )}
                                >
                                    <p className="text-sm font-medium">
                                        {option.label}
                                    </p>
                                    <p className="mt-0.5 text-xs text-muted-foreground">
                                        {option.description}
                                    </p>
                                </button>
                            ))}
                            <InputError message={form.errors.harness_type} />
                        </div>

                        <div className="flex flex-col gap-3">
                            <Button
                                type="button"
                                className="w-full"
                                onClick={nextStep}
                            >
                                Continue
                            </Button>

                            <Button
                                type="button"
                                variant="ghost"
                                className="w-full gap-2"
                                onClick={backStep}
                            >
                                <ArrowLeft className="size-4" />
                                Back
                            </Button>
                        </div>
                    </>
                )}

                {step === 'business' && (
                    <>
                        <div className="grid gap-4">
                            <div className="grid gap-2">
                                <Label htmlFor="company_name">
                                    Company name
                                </Label>
                                <Input
                                    id="company_name"
                                    value={form.data.company_name}
                                    onChange={(e) =>
                                        form.setData(
                                            'company_name',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="e.g. Ringing.io"
                                    autoFocus
                                />
                                <InputError
                                    message={form.errors.company_name}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="company_url">Website</Label>
                                <Input
                                    id="company_url"
                                    value={form.data.company_url}
                                    onChange={(e) =>
                                        form.setData(
                                            'company_url',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="https://ringing.io"
                                />
                                <InputError message={form.errors.company_url} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="company_description">
                                    What does your company do?
                                </Label>
                                <Textarea
                                    id="company_description"
                                    value={form.data.company_description}
                                    onChange={(e) =>
                                        form.setData(
                                            'company_description',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="e.g. AI-powered phone system for sales teams. We help businesses close more deals with intelligent call routing and real-time coaching."
                                    rows={3}
                                />
                                <InputError
                                    message={form.errors.company_description}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="target_market">
                                    Target market
                                </Label>
                                <Input
                                    id="target_market"
                                    value={form.data.target_market}
                                    onChange={(e) =>
                                        form.setData(
                                            'target_market',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="e.g. B2B SaaS companies, mid-market sales teams"
                                />
                                <InputError
                                    message={form.errors.target_market}
                                />
                            </div>
                        </div>

                        <div className="flex flex-col gap-3">
                            {isBusinessSubmitStep ? (
                                <Button
                                    type="submit"
                                    className="w-full"
                                    disabled={form.processing}
                                >
                                    {form.processing
                                        ? 'Creating...'
                                        : 'Create Team'}
                                </Button>
                            ) : (
                                <Button
                                    type="button"
                                    className="w-full"
                                    onClick={nextStep}
                                >
                                    Continue
                                </Button>
                            )}

                            <Button
                                type="button"
                                variant="ghost"
                                className="w-full gap-2"
                                onClick={backStep}
                            >
                                <ArrowLeft className="size-4" />
                                Back
                            </Button>
                        </div>
                    </>
                )}

                {step === 'provider' && cloudProviderSelectionEnabled && (
                    <>
                        <div className="grid gap-3">
                            {availableProviders.map((option) => (
                                <button
                                    key={option.value}
                                    type="button"
                                    onClick={() =>
                                        form.setData(
                                            'cloud_provider',
                                            option.value,
                                        )
                                    }
                                    className={cn(
                                        'rounded-xl border px-4 py-4 text-left transition-all',
                                        ('cloud_provider' in form.data
                                            ? form.data.cloud_provider
                                            : defaultProvider) === option.value
                                            ? 'border-foreground bg-accent shadow-sm'
                                            : 'border-border hover:border-foreground/30',
                                    )}
                                >
                                    <p className="text-sm font-medium">
                                        {option.label}
                                    </p>
                                    <p className="mt-0.5 text-xs text-muted-foreground">
                                        {option.description}
                                    </p>
                                </button>
                            ))}
                            <InputError
                                message={
                                    'cloud_provider' in form.errors
                                        ? (
                                              form.errors as Record<
                                                  string,
                                                  string
                                              >
                                          ).cloud_provider
                                        : undefined
                                }
                            />
                        </div>

                        <div className="flex flex-col gap-3">
                            <Button
                                type="submit"
                                className="w-full"
                                disabled={form.processing}
                            >
                                {form.processing
                                    ? 'Creating...'
                                    : 'Create Team'}
                            </Button>

                            <Button
                                type="button"
                                variant="ghost"
                                className="w-full gap-2"
                                onClick={backStep}
                            >
                                <ArrowLeft className="size-4" />
                                Back
                            </Button>
                        </div>
                    </>
                )}
            </form>
        </AuthLayout>
    );
}
