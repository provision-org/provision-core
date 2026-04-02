import { Head, Link, router } from '@inertiajs/react';
import { Clock, LogOut } from 'lucide-react';
import AppLogoIcon from '@/components/app-logo-icon';
import { Button } from '@/components/ui/button';
import { home } from '@/routes';

export default function Waitlist({ calendlyUrl }: { calendlyUrl?: string }) {
    return (
        <>
            <Head title="You're on the list" />

            <div className="flex min-h-svh flex-col items-center bg-background px-4 py-12 md:px-8">
                <div className="w-full max-w-2xl">
                    <div className="flex flex-col items-center gap-8">
                        <Link
                            href={home()}
                            className="flex flex-col items-center gap-2 font-medium"
                        >
                            <div className="flex h-10 w-10 items-center justify-center rounded-md">
                                <AppLogoIcon className="size-10 fill-current text-[var(--foreground)] dark:text-white" />
                            </div>
                        </Link>

                        <div className="space-y-3 text-center">
                            <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-muted">
                                <Clock className="h-6 w-6 text-muted-foreground" />
                            </div>
                            <h1 className="text-2xl font-semibold tracking-tight">
                                You&apos;re on the list
                            </h1>
                            <p className="mx-auto max-w-md text-sm text-muted-foreground">
                                We&apos;re onboarding teams in batches to ensure
                                the best experience. Book a quick call with us
                                and we&apos;ll get you set up.
                            </p>
                        </div>

                        {calendlyUrl && (
                            <div className="w-full overflow-hidden rounded-lg border">
                                <div
                                    className="calendly-inline-widget h-[660px] w-full"
                                    data-url={calendlyUrl}
                                />
                                <script
                                    type="text/javascript"
                                    src="https://assets.calendly.com/assets/external/widget.js"
                                    async
                                />
                            </div>
                        )}

                        <Button
                            variant="ghost"
                            size="sm"
                            className="text-muted-foreground"
                            onClick={() => router.post('/logout')}
                        >
                            <LogOut className="mr-2 h-4 w-4" />
                            Sign out
                        </Button>
                    </div>
                </div>
            </div>
        </>
    );
}
