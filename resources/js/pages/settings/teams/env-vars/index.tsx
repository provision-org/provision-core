import { Transition } from '@headlessui/react';
import { Form, Head, router } from '@inertiajs/react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import type { BreadcrumbItem, Team } from '@/types';

type EnvVarItem = {
    id: number;
    key: string;
    value_preview: string;
    is_secret: boolean;
    created_at: string;
    updated_at: string;
};

export default function EnvVarsIndex({
    team,
    envVars,
}: {
    team: Team;
    envVars: EnvVarItem[];
}) {
    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'Environment Variables',
            href: `/settings/teams/${team.id}/env-vars`,
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Environment Variables" />

            <h1 className="sr-only">Environment Variables</h1>

            <SettingsLayout>
                {/* Existing Vars */}
                <div className="space-y-6">
                    <Heading
                        variant="small"
                        title="Environment Variables"
                        description="Manage environment variables for your team's server."
                    />

                    {envVars.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No environment variables configured yet. Add one
                            below.
                        </p>
                    ) : (
                        <div className="space-y-4">
                            {envVars.map((envVar) => (
                                <div
                                    key={envVar.id}
                                    className="flex items-center justify-between"
                                >
                                    <div>
                                        <p className="font-mono text-sm font-medium">
                                            {envVar.key}
                                        </p>
                                        <p className="font-mono text-sm text-muted-foreground">
                                            {envVar.value_preview}
                                        </p>
                                    </div>

                                    <div className="flex items-center gap-2">
                                        {envVar.is_secret && (
                                            <Badge variant="secondary">
                                                Secret
                                            </Badge>
                                        )}

                                        <Dialog>
                                            <DialogTrigger asChild>
                                                <Button
                                                    variant="destructive"
                                                    size="sm"
                                                >
                                                    Delete
                                                </Button>
                                            </DialogTrigger>
                                            <DialogContent>
                                                <DialogTitle>
                                                    Delete environment variable?
                                                </DialogTitle>
                                                <DialogDescription>
                                                    This will remove{' '}
                                                    <strong>
                                                        {envVar.key}
                                                    </strong>{' '}
                                                    from your server's
                                                    environment.
                                                </DialogDescription>

                                                <DialogFooter className="gap-2">
                                                    <DialogClose asChild>
                                                        <Button variant="secondary">
                                                            Cancel
                                                        </Button>
                                                    </DialogClose>

                                                    <Button
                                                        variant="destructive"
                                                        onClick={() => {
                                                            router.delete(
                                                                `/settings/teams/${team.id}/env-vars/${envVar.id}`,
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            );
                                                        }}
                                                    >
                                                        Delete
                                                    </Button>
                                                </DialogFooter>
                                            </DialogContent>
                                        </Dialog>
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </div>

                <Separator />

                {/* Add Var */}
                <div className="space-y-6">
                    <Heading
                        variant="small"
                        title="Add environment variable"
                        description="Add a new environment variable to your server."
                    />

                    <Form
                        action={`/settings/teams/${team.id}/env-vars`}
                        method="post"
                        options={{
                            preserveScroll: true,
                        }}
                        resetOnSuccess
                        className="space-y-6"
                    >
                        {({ processing, recentlySuccessful, errors }) => (
                            <>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="grid gap-2">
                                        <Label htmlFor="key">Key</Label>

                                        <Input
                                            id="key"
                                            name="key"
                                            required
                                            placeholder="MY_VARIABLE"
                                            className="font-mono"
                                        />

                                        <InputError
                                            className="mt-2"
                                            message={errors.key}
                                        />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="value">Value</Label>

                                        <Input
                                            id="value"
                                            name="value"
                                            required
                                            placeholder="value"
                                        />

                                        <InputError
                                            className="mt-2"
                                            message={errors.value}
                                        />
                                    </div>
                                </div>

                                <div className="flex items-center gap-2">
                                    <Checkbox
                                        id="is_secret"
                                        name="is_secret"
                                        value="1"
                                    />
                                    <Label
                                        htmlFor="is_secret"
                                        className="cursor-pointer"
                                    >
                                        Mark as secret (value will be masked)
                                    </Label>
                                </div>

                                <div className="flex items-center gap-4">
                                    <Button disabled={processing}>
                                        Add Variable
                                    </Button>

                                    <Transition
                                        show={recentlySuccessful}
                                        enter="transition ease-in-out"
                                        enterFrom="opacity-0"
                                        leave="transition ease-in-out"
                                        leaveTo="opacity-0"
                                    >
                                        <p className="text-sm text-neutral-600">
                                            Added
                                        </p>
                                    </Transition>
                                </div>
                            </>
                        )}
                    </Form>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
