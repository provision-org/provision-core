<?php

namespace App\Jobs;

use App\Models\TeamEmailDomain;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Provision\MailboxKit\Services\MailboxKitService;

/**
 * Periodically re-check unverified custom email domains against MailboxKit so a
 * team's domain flips to verified once DNS propagates — without the admin
 * having to return and click "Verify". Mirrors EmailDomainController::verify.
 */
class VerifyEmailDomainsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        if (! class_exists(MailboxKitService::class)) {
            return;
        }

        $mailboxKit = app(MailboxKitService::class);

        TeamEmailDomain::query()
            ->where('is_verified', false)
            ->orderBy('last_checked_at')
            ->limit(100)
            ->get()
            ->each(function (TeamEmailDomain $domain) use ($mailboxKit) {
                try {
                    $payload = $mailboxKit->verifyDomain($domain->mailboxkit_domain_id)['data'] ?? [];

                    $domain->update([
                        'is_verified' => (bool) ($payload['is_verified'] ?? false),
                        'mx_verified' => (bool) ($payload['mx_verified'] ?? false),
                        'spf_verified' => (bool) ($payload['spf_verified'] ?? false),
                        'dkim_verified' => (bool) ($payload['dkim_verified'] ?? false),
                        'dmarc_verified' => (bool) ($payload['dmarc_verified'] ?? false),
                        'verified_at' => isset($payload['verified_at']) ? Carbon::parse($payload['verified_at']) : null,
                        'last_checked_at' => now(),
                    ]);
                } catch (\Throwable $e) {
                    $domain->update(['last_checked_at' => now()]);
                    Log::warning('Scheduled MailboxKit domain verification failed', [
                        'team_id' => $domain->team_id,
                        'domain_id' => $domain->mailboxkit_domain_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            });
    }
}
