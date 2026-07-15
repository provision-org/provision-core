import { Head, Link, useForm, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    Check,
    ChevronDown,
    Copy,
    LoaderCircle,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
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

type AwsVerifyState =
    | { status: 'idle' }
    | { status: 'verifying' }
    | { status: 'verified'; accountId: string }
    | { status: 'error'; message: string };

function csrfToken(): string {
    return decodeURIComponent(
        document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '',
    );
}

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
        <div className="relative w-full max-w-full min-w-0">
            <pre className="max-h-48 w-full max-w-full overflow-x-auto overflow-y-auto rounded border border-border bg-muted/40 p-3 pr-10 font-mono text-[11px] leading-relaxed">
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
        <Collapsible className="min-w-0 rounded-lg border border-border">
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
            {/* Block flow (not grid) so the policy <pre> is width-constrained
                by its parent and scrolls instead of stretching the card. */}
            <CollapsibleContent className="max-w-full space-y-2.5 px-3 pb-3">
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

type Step = 'name' | 'harness' | 'business' | 'provider' | 'aws';

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
    const { teams, errors: pageErrorsProp } = usePage<SharedData>().props;
    const hasExistingTeams = teams && teams.length > 0;
    const pageErrors = (pageErrorsProp ?? {}) as Record<string, string>;

    // When the server redirects back with validation errors (e.g. store-time
    // AWS verification failure), the wizard remounts on step one and the
    // errors would never be seen. Open on the earliest step that has one.
    function stepForServerErrors(): Step {
        if (pageErrors.name) {
            return 'name';
        }
        if (pageErrors.harness_type && harnessSelectionEnabled) {
            return 'harness';
        }
        if (
            pageErrors.company_name ||
            pageErrors.company_url ||
            pageErrors.company_description ||
            pageErrors.target_market
        ) {
            return 'business';
        }
        if (pageErrors.cloud_provider && cloudProviderSelectionEnabled) {
            return 'provider';
        }
        if (
            cloudProviderSelectionEnabled &&
            byoCloudEnabled &&
            Object.keys(pageErrors).some((key) => key.startsWith('aws_'))
        ) {
            return 'aws';
        }
        return 'name';
    }

    const [step, setStep] = useState<Step>(stepForServerErrors);

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

    // Seed server-flashed errors into the form once, so InputError renders
    // them even after a fresh mount (useForm starts with empty errors).
    const seededServerErrors = useRef(false);
    const setFormError = form.setError;
    useEffect(() => {
        if (seededServerErrors.current) {
            return;
        }
        seededServerErrors.current = true;
        if (Object.keys(pageErrors).length > 0) {
            setFormError(pageErrors as Record<string, string>);
        }
    });

    const selectedProvider =
        'cloud_provider' in form.data
            ? form.data.cloud_provider
            : defaultProvider;
    const formErrors = form.errors as Record<string, string | undefined>;

    const awsKeyId =
        ('aws_key_id' in form.data ? form.data.aws_key_id : '') ?? '';
    const awsSecret =
        ('aws_secret' in form.data ? form.data.aws_secret : '') ?? '';
    const awsRegion =
        ('aws_region' in form.data ? form.data.aws_region : '') || 'us-east-1';

    const [awsVerify, setAwsVerify] = useState<AwsVerifyState>({
        status: 'idle',
    });

    const awsSelected = selectedProvider === 'aws' && byoCloudEnabled;

    // Any change to the fields that feed verification invalidates it.
    function setAwsField(
        field: 'aws_key_id' | 'aws_secret' | 'aws_region',
        value: string,
    ) {
        form.setData(field, value);
        setAwsVerify({ status: 'idle' });
    }

    async function verifyAwsConnection() {
        setAwsVerify({ status: 'verifying' });

        try {
            const response = await fetch('/settings/teams/verify-aws', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({
                    aws_key_id: awsKeyId,
                    aws_secret: awsSecret,
                    aws_region: awsRegion,
                }),
            });
            const data = await response.json();

            if (response.ok && data.verified) {
                setAwsVerify({
                    status: 'verified',
                    accountId: data.account_id,
                });
            } else {
                setAwsVerify({
                    status: 'error',
                    message:
                        data.message ??
                        'Verification failed. Check your credentials and try again.',
                });
            }
        } catch {
            setAwsVerify({
                status: 'error',
                message:
                    'Verification failed. Check your connection and try again.',
            });
        }
    }

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
        } else if (step === 'provider') {
            if (awsSelected) {
                setStep('aws');
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
        } else if (step === 'aws') {
            setStep('provider');
        }
    }

    const stepTitles: Record<Step, string> = {
        name: 'Create a team',
        harness: 'Choose your agent framework',
        business: `About ${form.data.name}`,
        provider: 'Where should your agents run?',
        aws: 'Connect your AWS account',
    };

    const stepDescriptions: Record<Step, string> = {
        name: 'Create a team to deploy your first AI employee.',
        harness: 'This determines how your agents run tasks.',
        business: 'Help your agents understand your business better.',
        provider: 'Choose the infrastructure for your agent servers.',
        aws: 'Your agents will run on EC2 in your account. Set up access once for this team.',
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

                        <div className="flex flex-col gap-3">
                            {awsSelected ? (
                                <Button
                                    type="button"
                                    className="w-full"
                                    onClick={nextStep}
                                >
                                    Continue
                                </Button>
                            ) : (
                                <Button
                                    type="submit"
                                    className="w-full"
                                    disabled={form.processing}
                                >
                                    {form.processing
                                        ? 'Creating...'
                                        : 'Create Team'}
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

                {step === 'aws' &&
                    cloudProviderSelectionEnabled &&
                    byoCloudEnabled && (
                        <div className="space-y-8">
                            <div className="space-y-3">
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
                            </div>

                            <div className="grid gap-4 border-t border-border pt-6">
                                <div className="grid gap-2">
                                    <Label htmlFor="aws_key_id">
                                        AWS access key ID
                                    </Label>
                                    <Input
                                        id="aws_key_id"
                                        value={awsKeyId}
                                        onChange={(e) =>
                                            setAwsField(
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
                                        value={awsSecret}
                                        onChange={(e) =>
                                            setAwsField(
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
                                        value={awsRegion}
                                        onValueChange={(value) =>
                                            setAwsField('aws_region', value)
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

                                <div className="flex flex-wrap items-center gap-3 pt-1">
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        size="sm"
                                        onClick={verifyAwsConnection}
                                        disabled={
                                            awsVerify.status === 'verifying' ||
                                            !awsKeyId.trim() ||
                                            !awsSecret.trim()
                                        }
                                    >
                                        {awsVerify.status === 'verifying' && (
                                            <LoaderCircle className="size-4 animate-spin" />
                                        )}
                                        {awsVerify.status === 'verifying'
                                            ? 'Verifying...'
                                            : 'Verify connection'}
                                    </Button>
                                    {awsVerify.status === 'verified' && (
                                        <span className="flex items-center gap-1.5 text-xs text-green-600">
                                            <Check className="size-3.5" />
                                            Verified &middot; account{' '}
                                            {awsVerify.accountId}
                                        </span>
                                    )}
                                    {awsVerify.status === 'error' && (
                                        <span className="text-xs text-red-600">
                                            {awsVerify.message}
                                        </span>
                                    )}
                                </div>

                                <p className="text-xs text-muted-foreground">
                                    Credentials are stored encrypted, per team.
                                </p>
                            </div>

                            <div className="flex flex-col gap-3">
                                <Button
                                    type="submit"
                                    className="w-full"
                                    disabled={
                                        form.processing ||
                                        awsVerify.status !== 'verified'
                                    }
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
                        </div>
                    )}
            </form>
        </AuthLayout>
    );
}
