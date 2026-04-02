<?php

namespace App\Concerns;

trait BuildsServerCallbackUrl
{
    private function buildCallbackUrl(): string
    {
        $expiresAt = now()->addMinutes(30)->timestamp;
        $signature = hash_hmac('sha256', $this->server->id.'|'.$expiresAt, config('app.key'));

        return url("/api/webhooks/server-ready?server_id={$this->server->id}&expires_at={$expiresAt}&signature={$signature}");
    }
}
