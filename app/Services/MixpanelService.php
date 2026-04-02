<?php

namespace App\Services;

use App\Models\User;
use Mixpanel;

class MixpanelService
{
    private ?Mixpanel $mp = null;

    private function client(): ?Mixpanel
    {
        if ($this->mp) {
            return $this->mp;
        }

        $token = config('services.mixpanel.token');

        if (! $token) {
            return null;
        }

        $this->mp = Mixpanel::getInstance($token);

        return $this->mp;
    }

    public function identify(User $user): void
    {
        $mp = $this->client();
        if (! $mp) {
            return;
        }

        $mp->people->set($user->id, [
            '$name' => $user->name,
            '$email' => $user->email,
            '$created' => $user->created_at?->toIso8601String(),
            'google_connected' => $user->google_id !== null,
            'team' => $user->currentTeam?->name,
            'plan' => $user->currentTeam?->plan?->value ?? 'free',
        ]);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    public function track(User $user, string $event, array $properties = []): void
    {
        $mp = $this->client();
        if (! $mp) {
            return;
        }

        $mp->track($event, array_merge([
            'distinct_id' => $user->id,
        ], $properties));
    }
}
