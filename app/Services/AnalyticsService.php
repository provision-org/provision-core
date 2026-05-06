<?php

namespace App\Services;

use App\Models\User;
use PostHog\Client;
use PostHog\PostHog;

class AnalyticsService
{
    private bool $initialized = false;

    private bool $enabled = false;

    private function ensureInitialized(): bool
    {
        if ($this->initialized) {
            return $this->enabled;
        }

        $this->initialized = true;

        $key = config('services.posthog.key');
        if (! is_string($key) || $key === '') {
            return false;
        }

        $host = config('services.posthog.host', 'https://us.i.posthog.com');

        PostHog::init($key, ['host' => $host], new Client($key, ['host' => $host]));
        $this->enabled = true;

        return true;
    }

    public function identify(User $user): void
    {
        if (! $this->ensureInitialized()) {
            return;
        }

        PostHog::identify([
            'distinctId' => (string) $user->id,
            'properties' => [
                'email' => $user->email,
                'name' => $user->name,
                'created_at' => $user->created_at?->toIso8601String(),
                'google_connected' => $user->google_id !== null,
                'team' => $user->currentTeam?->name,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    public function track(User $user, string $event, array $properties = []): void
    {
        if (! $this->ensureInitialized()) {
            return;
        }

        PostHog::capture([
            'distinctId' => (string) $user->id,
            'event' => $event,
            'properties' => $properties,
        ]);
    }
}
