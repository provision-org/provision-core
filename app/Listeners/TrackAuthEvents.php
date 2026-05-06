<?php

namespace App\Listeners;

use App\Services\AnalyticsService;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Events\Attribute\AsListener;

class TrackAuthEvents implements ShouldHandleEventsAfterCommit
{
    public function __construct(private AnalyticsService $analytics) {}

    #[AsListener(Registered::class)]
    public function handleRegistered(Registered $event): void
    {
        $user = $event->user;

        $this->analytics->identify($user);
        $this->analytics->track($user, 'Signed Up', [
            'method' => $user->google_id ? 'google' : 'email',
        ]);
    }

    #[AsListener(Login::class)]
    public function handleLogin(Login $event): void
    {
        $user = $event->user;

        $this->analytics->identify($user);
        $this->analytics->track($user, 'Logged In', [
            'method' => $user->google_id ? 'google' : 'email',
        ]);
    }
}
