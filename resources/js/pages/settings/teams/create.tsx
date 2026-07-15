import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, Check, ChevronDown, Copy } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import AuthLayout from '@/layouts/auth-layout';
import { cn } from '@/lib/utils';
import type { SharedData } from '@/types';

const awsRegions = [
    { value: 'us-east-1', label: 'us-east-1 (N. Virginia)' },
    { value: 'us-west-2', label: 'us-west-2 (Oregon)' },
    { value: 'eu-central-1', label: 'eu-central-1 (Frankfurt)' },
    { value: 'ap-southeast-1', label: 'ap-southeast-1 (Singapore)' },
];

const provisioningPolicy = `{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Sid": "ProvisionServerManagement",
      "Effect": "Allow",
      "Action": [
        "ec2:RunInstances", "ec2:TerminateInstances", "ec2:DescribeInstances",
        "ec2:DescribeImages", "ec2:CreateTags", "ec2:CreateSecurityGroup",
        "ec2:DeleteSecurityGroup", "ec2:AuthorizeSecurityGroupIngress",
        "ec2:DescribeSecurityGroups", "ec2:DescribeVpcs", "ec2:ModifyInstanceAttribute",
        "iam:PassRole"
      ],
      "Resource": "*"
    }
  ]
}`;

const bedrockPolicy = `{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Sid": "ProvisionBedrockAccess",
      "Effect": "Allow",
      "Action": [
        "bedrock:InvokeModel", "bedrock:InvokeModelWithResponseStream",
        "bedrock:ListFoundationModels", "bedrock:ListInferenceProfiles"
      ],
      "Resource": "*"
    }
  ]
}`;

function PolicyBlock({ policy }: { policy: string }) {
    const [copied, setCopied] = useState(false);

    const onCopy = () => {
        navigator.clipboard.writeText(policy);
        setCopied(true);
        setTimeout(() => setCopied(false), 1500);
    };

    return (
        <div className="relative">
            <pre className="max-h-48 overflow-auto rounded border border-border bg-muted/40 p-3 pr-10 font-mono text-[11px] leading-relaxed">
                {policy}
            </pre>
            <button
                type="button"
                onClick={onCopy}
                className="absolute top-2 right-2 rounded p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground"
                title="Copy policy JSON"
            >
                {copied ? (
                    <Check className="size-3.5 text-green-600" />
                ) : (
                    <Copy className="size-3.5" />
                )}
            </button>
        </div>
    );
}

function SetupStep({
    number,
    title,
    hint,
    children,
}: {
    number: number;
    title: string;
    hint?: string;
    children: React.ReactNode;
}) {
    return (
        <Collapsible className="rounded-lg border border-border">
            <CollapsibleTrigger className="group flex w-full items-center gap-2 px-3 py-2.5 text-left">
                <span className="flex size-5 shrink-0 items-center justify-center rounded-full bg-muted font-mono text-[10px]">
                    {number}
                </span>
                <span className="flex-1 text-sm font-medium">{title}</span>
                {hint && (
                    <span className="text-[10px] text-muted-foreground">
                        {hint}
                    </span>
                )}
                <ChevronDown className="size-4 shrink-0 text-muted-foreground transition-transform group-data-[state=open]:rotate-180" />
            </CollapsibleTrigger>
            <CollapsibleContent className="grid gap-2 px-3 pb-3">
                {children}
            </CollapsibleContent>
        </Collapsible>
    );
}

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
    byoCloudEnabled = false,
}: {
    harnessSelectionEnabled?: boolean;
    cloudProviderSelectionEnabled?: boolean;
    availableProviders?: {
        value: string;
        label: string;
        description: string;
    }[];
    defaultProvider?: string;
    byoCloudEnabled?: boolean;
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
            ? {
                  cloud_provider: defaultProvider,
                  aws_key_id: '',
                  aws_secret: '',
                  aws_region: 'us-east-1',
                  aws_instance_profile: '',
              }
            : {}),
    });

    const selectedProvider =
        'cloud_provider' in form.data
            ? form.data.cloud_provider
            : defaultProvider;
    const formErrors = form.errors as Record<string, string | undefined>;

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
                                        selectedProvider === option.value
                                            ? 'border-foreground bg-accent shadow-sm'
                                            : 'border-border hover:border-foreground/30',
                                    )}
                                >
                                    <div className="flex items-center gap-2">
                                        <p className="text-sm font-medium">
                                            {option.label}
                                        </p>
                                        {option.value === 'aws' && (
                                            <span className="rounded bg-muted px-1.5 py-0.5 font-mono text-[10px] text-muted-foreground">
                                                Your AWS account
                                            </span>
                                        )}
                                    </div>
                                    <p className="mt-0.5 text-xs text-muted-foreground">
                                        {option.description}
                                    </p>
                                </button>
                            ))}
                            <InputError message={formErrors.cloud_provider} />
                        </div>

                        {byoCloudEnabled && selectedProvider === 'aws' && (
                            <div className="grid gap-3 rounded-xl border border-border p-4">
                                <SetupStep
                                    number={1}
                                    title="Create an IAM user for provisioning"
                                >
                                    <p className="text-xs text-muted-foreground">
                                        IAM &rarr; Users &rarr; Create user
                                        &rarr; attach this inline policy &rarr;
                                        create an access key.
                                    </p>
                                    <PolicyBlock policy={provisioningPolicy} />
                                </SetupStep>

                                <SetupStep
                                    number={2}
                                    title="Create the Bedrock role for your agents"
                                    hint="Optional"
                                >
                                    <p className="text-xs text-muted-foreground">
                                        IAM &rarr; Roles &rarr; Create role
                                        &rarr; trusted entity: EC2 &rarr; attach
                                        this policy &rarr; note the
                                        instance-profile name. Needed for the
                                        Bedrock model tier.
                                    </p>
                                    <PolicyBlock policy={bedrockPolicy} />
                                </SetupStep>

                                <div className="flex items-center gap-2 pt-1">
                                    <span className="flex size-5 shrink-0 items-center justify-center rounded-full bg-muted font-mono text-[10px]">
                                        3
                                    </span>
                                    <span className="text-sm font-medium">
                                        Connect
                                    </span>
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="aws_key_id">
                                        AWS access key ID
                                    </Label>
                                    <Input
                                        id="aws_key_id"
                                        value={
                                            'aws_key_id' in form.data
                                                ? form.data.aws_key_id
                                                : ''
                                        }
                                        onChange={(e) =>
                                            form.setData(
                                                'aws_key_id',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="AKIA..."
                                        autoComplete="off"
                                    />
                                    <InputError
                                        message={formErrors.aws_key_id}
                                    />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="aws_secret">
                                        AWS secret access key
                                    </Label>
                                    <Input
                                        id="aws_secret"
                                        type="password"
                                        value={
                                            'aws_secret' in form.data
                                                ? form.data.aws_secret
                                                : ''
                                        }
                                        onChange={(e) =>
                                            form.setData(
                                                'aws_secret',
                                                e.target.value,
                                            )
                                        }
                                        autoComplete="off"
                                    />
                                    <InputError
                                        message={formErrors.aws_secret}
                                    />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="aws_region">
                                        AWS region
                                    </Label>
                                    <Select
                                        value={
                                            'aws_region' in form.data
                                                ? form.data.aws_region
                                                : 'us-east-1'
                                        }
                                        onValueChange={(value) =>
                                            form.setData('aws_region', value)
                                        }
                                    >
                                        <SelectTrigger id="aws_region">
                                            <SelectValue placeholder="Select a region" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {awsRegions.map((region) => (
                                                <SelectItem
                                                    key={region.value}
                                                    value={region.value}
                                                >
                                                    {region.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError
                                        message={formErrors.aws_region}
                                    />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="aws_instance_profile">
                                        Instance profile name (optional)
                                    </Label>
                                    <Input
                                        id="aws_instance_profile"
                                        value={
                                            'aws_instance_profile' in form.data
                                                ? form.data.aws_instance_profile
                                                : ''
                                        }
                                        onChange={(e) =>
                                            form.setData(
                                                'aws_instance_profile',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="e.g. provision-bedrock"
                                        autoComplete="off"
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        Enables the Bedrock model tier. Agents
                                        authenticate with this role, no API
                                        keys.
                                    </p>
                                    <InputError
                                        message={
                                            formErrors.aws_instance_profile
                                        }
                                    />
                                </div>

                                <p className="text-xs text-muted-foreground">
                                    Credentials are stored encrypted, per team.
                                </p>
                            </div>
                        )}

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
