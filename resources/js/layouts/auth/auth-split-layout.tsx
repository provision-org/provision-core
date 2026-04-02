import { Link, usePage } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import { home } from '@/routes';
import type { AuthLayoutProps, SharedData } from '@/types';

export default function AuthSplitLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    const { name } = usePage<SharedData>().props;
    return (
        <div className="font-ui relative flex min-h-screen bg-background text-foreground antialiased selection:bg-primary/20">
            {/* Left/Top side: Form */}
            <div className="relative z-10 flex w-full flex-col justify-center border-r border-foreground/[0.05] bg-background px-6 py-12 lg:w-1/2 lg:px-16 xl:px-24">
                <div className="mx-auto flex w-full max-w-sm flex-col">
                    <div className="mb-10 flex items-center justify-between">
                        <Link
                            href={home()}
                            className="group flex items-center gap-2.5"
                        >
                            <div className="flex h-9 w-9 items-center justify-center rounded-xl border border-foreground/[0.06] bg-foreground/[0.03] shadow-sm transition-transform group-hover:scale-105">
                                <AppLogoIcon className="size-5 fill-current text-foreground" />
                            </div>
                            <span className="text-[15px] font-bold tracking-tight text-foreground/90">
                                {name || 'Provision'}
                            </span>
                        </Link>
                    </div>

                    <div className="mb-8 flex flex-col items-start gap-2">
                        <h1 className="font-editorial text-3xl leading-tight tracking-tight text-foreground">
                            {title}
                        </h1>
                        <p className="text-[14px] text-foreground/60">
                            {description}
                        </p>
                    </div>

                    {children}
                </div>
            </div>

            {/* Right side: Branding/Visuals */}
            <div className="relative hidden w-1/2 overflow-hidden bg-zinc-950 lg:flex lg:flex-col">
                {/* Background Patterns */}
                <div
                    className="pointer-events-none absolute inset-0 z-0 opacity-[0.15]"
                    style={{
                        backgroundImage: `linear-gradient(to right, rgba(255, 255, 255, 0.1) 1px, transparent 1px),
                                          linear-gradient(to bottom, rgba(255, 255, 255, 0.1) 1px, transparent 1px)`,
                        backgroundSize: '40px 40px',
                        maskImage:
                            'linear-gradient(to bottom, white, transparent)',
                    }}
                />

                {/* Glowing Orbs (CSS-only approximation) */}
                <div className="absolute top-1/4 left-1/4 h-96 w-96 animate-pulse rounded-full bg-indigo-500/20 mix-blend-screen blur-[120px]" />
                <div
                    className="absolute right-1/4 bottom-1/4 h-80 w-80 animate-pulse rounded-full bg-purple-500/20 mix-blend-screen blur-[100px]"
                    style={{ animationDelay: '2s' }}
                />

                <div className="relative z-10 flex h-full flex-col justify-end p-12 lg:p-16 xl:p-24">
                    <div className="max-w-xl">
                        <p className="font-editorial text-4xl leading-[1.15] tracking-tight text-white/90 lg:text-[2.8rem]">
                            Provision helps you run a team of agents for
                            your company.
                        </p>
                    </div>
                </div>
            </div>

            <style>{`
                .font-editorial { font-family: 'Instrument Serif', Georgia, serif; }
                .font-ui       { font-family: 'Plus Jakarta Sans', system-ui, sans-serif; }
            `}</style>
        </div>
    );
}
