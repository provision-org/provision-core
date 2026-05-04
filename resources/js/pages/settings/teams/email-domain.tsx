import { Form, Head, router } from '@inertiajs/react';
import { Check, Copy, X } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import type { BreadcrumbItem, Team } from '@/types';

type DnsRecord = {
    type: string;
    host: string;
    value: string;
    priority?: number;
};

type DomainPayload = {
    id: string;
    name: string;
    is_verified: boolean;
    mx_verified: boolean;
    spf_verified: boolean;
    dkim_verified: boolean;
    dmarc_verified: boolean;
    dns_records: Record<string, DnsRecord> | null;
    verified_at: string | null;
    last_checked_at: string | null;
};

function CopyableField({ value }: { value: string }) {
    const [copied, setCopied] = useState(false);

    const onCopy = () => {
        navigator.clipboard.writeText(value);
        setCopied(true);
        setTimeout(() => setCopied(false), 1500);
    };

    return (
        <button
            type="button"
            onClick={onCopy}
            className="group flex w-full items-start gap-2 rounded border border-border bg-muted/40 px-2 py-1.5 text-left font-mono text-xs hover:bg-muted"
            title="Click to copy"
        >
            <span className="flex-1 break-all">{value}</span>
            {copied ? (
                <Check className="size-3.5 shrink-0 text-green-600" />
            ) : (
                <Copy className="size-3.5 shrink-0 text-muted-foreground group-hover:text-foreground" />
            )}
        </button>
    );
}

function VerificationRow({ label, ok }: { label: string; ok: boolean }) {
    return (
        <div className="flex items-center gap-2 text-sm">
            {ok ? (
                <Check className="size-4 text-green-600" />
            ) : (
                <X className="size-4 text-muted-foreground" />
            )}
            <span className={ok ? 'text-foreground' : 'text-muted-foreground'}>
                {label}
            </span>
        </div>
    );
}

function DnsRecordTable({ records }: { records: Record<string, DnsRecord> }) {
    const order: Array<keyof typeof records> = ['mx', 'spf', 'dkim', 'dmarc'];

    return (
        <div className="overflow-hidden rounded-md border border-border">
            <table className="w-full text-sm">
                <thead className="bg-muted/60 text-left text-xs uppercase text-muted-foreground">
                    <tr>
                        <th className="px-3 py-2 font-medium">Type</th>
                        <th className="px-3 py-2 font-medium">Host</th>
                        <th className="px-3 py-2 font-medium">Value</th>
                    </tr>
                </thead>
                <tbody>
                    {order
                        .filter((key) => records[key])
                        .map((key) => {
                            const r = records[key];
                            return (
                                <tr
                                    key={key as string}
                                    className="border-t border-border align-top"
                                >
                                    <td className="px-3 py-2 font-mono text-xs">
                                        {r.type}
                                        {r.priority !== undefined && (
                                            <span className="ml-1 text-muted-foreground">
                                                (priority {r.priority})
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-3 py-2">
                                        <CopyableField value={r.host} />
                                    </td>
                                    <td className="px-3 py-2">
                                        <CopyableField value={r.value} />
                                    </td>
                                </tr>
                            );
                        })}
                </tbody>
            </table>
        </div>
    );
}

export default function EmailDomain({
    team,
    defaultDomain,
    domain,
}: {
    team: Team;
    defaultDomain: string;
    domain: DomainPayload | null;
}) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Team settings', href: `/settings/teams/${team.id}` },
        {
            title: 'Email domain',
            href: `/settings/teams/${team.id}/email-domain`,
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Email domain" />

            <h1 className="sr-only">Email domain</h1>

            <SettingsLayout>
                <div className="space-y-6">
                    <Heading
                        variant="small"
                        title="Custom email domain"
                        description={`Send agent email from your own domain instead of the default ${defaultDomain}.`}
                    />

                    {!domain && (
                        <RegisterForm teamId={team.id} />
                    )}

                    {domain && !domain.is_verified && (
                        <PendingVerification team={team} domain={domain} />
                    )}

                    {domain && domain.is_verified && (
                        <VerifiedDomain team={team} domain={domain} />
                    )}
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}

function RegisterForm({ teamId }: { teamId: string }) {
    return (
        <>
            <Alert>
                <AlertTitle>Use a subdomain</AlertTitle>
                <AlertDescription>
                    Connect a subdomain like{' '}
                    <span className="font-mono">email.yourdomain.com</span>, not
                    your apex. The DNS records below would override your
                    existing inbox if applied at the apex (e.g. Google
                    Workspace).
                </AlertDescription>
            </Alert>

            <Form
                action={`/settings/teams/${teamId}/email-domain`}
                method="post"
                options={{ preserveScroll: true }}
                className="space-y-6"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-2">
                            <Label htmlFor="name">Domain</Label>
                            <Input
                                id="name"
                                name="name"
                                placeholder="email.yourdomain.com"
                                autoComplete="off"
                                spellCheck={false}
                                required
                            />
                            <InputError className="mt-2" message={errors.name} />
                        </div>

                        <Button disabled={processing}>
                            Register domain
                        </Button>
                    </>
                )}
            </Form>
        </>
    );
}

function PendingVerification({
    team,
    domain,
}: {
    team: Team;
    domain: DomainPayload;
}) {
    return (
        <div className="space-y-6">
            <div className="flex items-center gap-3">
                <span className="font-mono text-sm">{domain.name}</span>
                <Badge variant="secondary">Awaiting DNS</Badge>
            </div>

            <Alert>
                <AlertTitle>Add these DNS records</AlertTitle>
                <AlertDescription>
                    Add the following records at your DNS provider (Cloudflare,
                    Route 53, etc.). Changes can take up to 48 hours to
                    propagate, but typically resolve in minutes.
                </AlertDescription>
            </Alert>

            {domain.dns_records ? (
                <DnsRecordTable records={domain.dns_records} />
            ) : (
                <p className="text-sm text-muted-foreground">
                    No DNS records returned by MailboxKit. Try removing and
                    re-registering the domain.
                </p>
            )}

            <Separator />

            <div className="space-y-3">
                <Heading
                    variant="small"
                    title="Verification"
                    description={
                        domain.last_checked_at
                            ? `Last checked ${new Date(domain.last_checked_at).toLocaleString()}`
                            : 'Not yet checked'
                    }
                />

                <div className="grid gap-1">
                    <VerificationRow label="MX" ok={domain.mx_verified} />
                    <VerificationRow label="SPF" ok={domain.spf_verified} />
                    <VerificationRow label="DKIM" ok={domain.dkim_verified} />
                    <VerificationRow label="DMARC" ok={domain.dmarc_verified} />
                </div>

                <div className="flex gap-2 pt-2">
                    <Button
                        size="sm"
                        onClick={() =>
                            router.post(
                                `/settings/teams/${team.id}/email-domain/verify`,
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        Re-check now
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() => {
                            if (
                                confirm(
                                    'Remove this domain? Existing agent mailboxes on it will keep working until reprovisioned.',
                                )
                            ) {
                                router.delete(
                                    `/settings/teams/${team.id}/email-domain`,
                                    { preserveScroll: true },
                                );
                            }
                        }}
                    >
                        Remove domain
                    </Button>
                </div>
            </div>
        </div>
    );
}

function VerifiedDomain({
    team,
    domain,
}: {
    team: Team;
    domain: DomainPayload;
}) {
    return (
        <div className="space-y-6">
            <div className="flex items-center gap-3">
                <span className="font-mono text-sm">{domain.name}</span>
                <Badge>Verified</Badge>
                {domain.verified_at && (
                    <span className="text-sm text-muted-foreground">
                        on {new Date(domain.verified_at).toLocaleDateString()}
                    </span>
                )}
            </div>

            <Alert>
                <AlertTitle>You're sending from {domain.name}</AlertTitle>
                <AlertDescription>
                    Newly created agents will get mailboxes like{' '}
                    <span className="font-mono">
                        agent_team@{domain.name}
                    </span>
                    . Existing mailboxes are unchanged until reprovisioned.
                </AlertDescription>
            </Alert>

            <div className="grid gap-1">
                <VerificationRow label="MX" ok={domain.mx_verified} />
                <VerificationRow label="SPF" ok={domain.spf_verified} />
                <VerificationRow label="DKIM" ok={domain.dkim_verified} />
                <VerificationRow label="DMARC" ok={domain.dmarc_verified} />
            </div>

            <div className="flex gap-2">
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() =>
                        router.post(
                            `/settings/teams/${team.id}/email-domain/verify`,
                            {},
                            { preserveScroll: true },
                        )
                    }
                >
                    Re-check
                </Button>
                <Button
                    size="sm"
                    variant="destructive"
                    onClick={() => {
                        if (
                            confirm(
                                'Remove this domain? Existing agent mailboxes on it will keep working until reprovisioned.',
                            )
                        ) {
                            router.delete(
                                `/settings/teams/${team.id}/email-domain`,
                                { preserveScroll: true },
                            );
                        }
                    }}
                >
                    Remove domain
                </Button>
            </div>
        </div>
    );
}
