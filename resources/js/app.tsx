import { createInertiaApp, router } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import '../css/app.css';
import { initializeTheme } from './hooks/use-appearance';
import { identifyUser, initPostHog, resetUser } from './lib/posthog';
import type { SharedData } from './types';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

let lastIdentifiedUserId: string | null = null;

function syncPostHogIdentity(shared: Partial<SharedData>): void {
    const userId = shared.auth?.user?.id ?? null;

    if (userId && userId !== lastIdentifiedUserId) {
        identifyUser(userId, {
            email: shared.auth?.user?.email,
            name: shared.auth?.user?.name,
        });
        lastIdentifiedUserId = userId;
    } else if (!userId && lastIdentifiedUserId) {
        resetUser();
        lastIdentifiedUserId = null;
    }
}

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) =>
        resolvePageComponent(
            `./pages/${name}.tsx`,
            import.meta.glob('./pages/**/*.tsx'),
        ),
    setup({ el, App, props }) {
        const shared = props.initialPage.props as Partial<SharedData>;
        initPostHog(shared.posthog ?? null);
        syncPostHogIdentity(shared);

        const root = createRoot(el);

        root.render(
            <StrictMode>
                <App {...props} />
            </StrictMode>,
        );
    },
    progress: {
        color: '#4B5563',
    },
});

router.on('success', (event) => {
    syncPostHogIdentity(event.detail.page.props as Partial<SharedData>);
});

// This will set light / dark mode on load...
initializeTheme();
