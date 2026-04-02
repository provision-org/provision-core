import { Head } from '@inertiajs/react';
import { Check } from 'lucide-react';

export default function ExtensionCallback() {
    return (
        <div className="flex min-h-svh items-center justify-center bg-background">
            <Head title="Extension Connected" />
            <div className="w-full max-w-sm text-center">
                <div className="mx-auto mb-4 flex size-12 items-center justify-center rounded-full bg-green-100 dark:bg-green-900/30">
                    <Check className="size-6 text-green-600 dark:text-green-400" />
                </div>
                <h1 className="text-xl font-bold">Connected!</h1>
                <p className="mt-2 text-sm text-muted-foreground">
                    The Provision extension is now connected to your account.
                    This tab will close automatically.
                </p>
            </div>
        </div>
    );
}
