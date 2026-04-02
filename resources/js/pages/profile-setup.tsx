import { Head, useForm } from '@inertiajs/react';
import { useEffect } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

const pronounOptions = [
    { value: 'he/him', label: 'He/Him' },
    { value: 'she/her', label: 'She/Her' },
    { value: 'they/them', label: 'They/Them' },
    { value: 'prefer_not_to_say', label: 'Prefer not to say' },
];

export default function ProfileSetup() {
    const detectedTimezone =
        Intl.DateTimeFormat().resolvedOptions().timeZone || '';

    const form = useForm({
        pronouns: '',
        timezone: detectedTimezone,
    });

    /* eslint-disable react-hooks/exhaustive-deps */
    useEffect(() => {
        if (detectedTimezone && !form.data.timezone) {
            form.setData('timezone', detectedTimezone);
        }
    }, []);
    /* eslint-enable react-hooks/exhaustive-deps */

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        form.post('/profile-setup');
    }

    return (
        <div className="flex min-h-svh flex-col items-center justify-center gap-6 bg-background p-6 md:p-10">
            <Head title="Complete your profile" />

            <div className="w-full max-w-md">
                <div className="flex flex-col items-center gap-8">
                    <div className="flex flex-col items-center gap-4">
                        <div className="mb-1 flex h-9 w-9 items-center justify-center rounded-md">
                            <AppLogoIcon className="size-9 fill-current text-[var(--foreground)] dark:text-white" />
                        </div>
                        <div className="space-y-2 text-center">
                            <h1 className="text-xl font-medium">
                                Complete your profile
                            </h1>
                            <p className="text-sm text-muted-foreground">
                                Tell us a bit about yourself.
                            </p>
                        </div>
                    </div>

                    <div className="w-full rounded-lg border p-6">
                        <form onSubmit={handleSubmit} className="space-y-5">
                            <div className="grid gap-2">
                                <Label htmlFor="pronouns">Pronouns</Label>
                                <Select
                                    value={form.data.pronouns}
                                    onValueChange={(v) =>
                                        form.setData('pronouns', v)
                                    }
                                >
                                    <SelectTrigger id="pronouns">
                                        <SelectValue placeholder="Select pronouns" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {pronounOptions.map((opt) => (
                                            <SelectItem
                                                key={opt.value}
                                                value={opt.value}
                                            >
                                                {opt.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={form.errors.pronouns} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="timezone">Timezone</Label>
                                <Input
                                    id="timezone"
                                    value={form.data.timezone}
                                    onChange={(e) =>
                                        form.setData('timezone', e.target.value)
                                    }
                                    placeholder="America/New_York"
                                />
                                <InputError message={form.errors.timezone} />
                            </div>

                            <div className="flex items-center justify-end pt-2">
                                <Button
                                    type="submit"
                                    disabled={form.processing}
                                >
                                    {form.processing ? 'Saving...' : 'Continue'}
                                </Button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    );
}
