<?php

namespace App\Services;

class SignedScriptUrlService
{
    /**
     * Build a signed URL for serving a script endpoint.
     */
    public function buildScriptUrl(string $routeName, array $params, int $ttlMinutes = 10): string
    {
        $expiresAt = now()->addMinutes($ttlMinutes)->timestamp;
        $entityId = implode('|', array_values($params));
        $signature = hash_hmac('sha256', "{$routeName}|{$entityId}|{$expiresAt}", config('app.key'));

        return url(route($routeName, $params + [
            'expires_at' => $expiresAt,
            'signature' => $signature,
        ], false));
    }

    /**
     * Build a signed callback URL for the script to POST back to.
     */
    public function buildCallbackUrl(string $routeName, array $params, int $ttlMinutes = 30): string
    {
        $expiresAt = now()->addMinutes($ttlMinutes)->timestamp;
        $entityId = implode('|', array_values($params));
        $signature = hash_hmac('sha256', "{$routeName}|{$entityId}|{$expiresAt}", config('app.key'));

        return url(route($routeName, $params + [
            'expires_at' => $expiresAt,
            'signature' => $signature,
        ], false));
    }

    /**
     * Verify a signed URL's signature and expiry.
     */
    public function verify(string $routeName, array $params, string $expiresAt, string $signature): bool
    {
        if ((int) $expiresAt < now()->timestamp) {
            return false;
        }

        $entityId = implode('|', array_values($params));
        $expected = hash_hmac('sha256', "{$routeName}|{$entityId}|{$expiresAt}", config('app.key'));

        return hash_equals($expected, $signature);
    }
}
