import posthog from 'posthog-js';

let initialized = false;

type PostHogConfig = { key: string; host: string };

/**
 * Initialize PostHog if the operator has set POSTHOG_KEY in the environment.
 * No-op if config is null (OSS self-host with analytics disabled) or if
 * already initialized. Safe to call from anywhere.
 */
export function initPostHog(config: PostHogConfig | null | undefined): void {
    if (initialized) return;
    if (typeof window === 'undefined') return;
    if (!config || !config.key) return;

    posthog.init(config.key, {
        api_host: config.host,
        defaults: '2026-01-30',
        capture_exceptions: true,
        debug: import.meta.env.DEV,
    });
    initialized = true;
}

/**
 * Tie subsequent events to an authenticated user. Call once after login,
 * with an opaque identifier (the user's ULID). No-op if PostHog isn't
 * initialized.
 */
export function identifyUser(
    userId: string | number,
    properties?: Record<string, unknown>,
): void {
    if (!initialized) return;
    posthog.identify(String(userId), properties);
}

/**
 * Clear the identified user — call on logout so the next visitor starts
 * with a fresh anonymous distinct_id.
 */
export function resetUser(): void {
    if (!initialized) return;
    posthog.reset();
}

/**
 * Capture an event only if PostHog is initialized. Drop-in safe for OSS
 * forks where analytics is disabled — never throws, never queues.
 */
export function captureIfReady(
    event: string,
    properties?: Record<string, unknown>,
): void {
    if (!initialized) return;
    posthog.capture(event, properties);
}

export { posthog };
