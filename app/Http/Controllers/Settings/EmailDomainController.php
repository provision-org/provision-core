<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\RegisterEmailDomainRequest;
use App\Models\Team;
use App\Models\TeamEmailDomain;
use Carbon\Carbon;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Provision\MailboxKit\Services\MailboxKitService;

class EmailDomainController extends Controller
{
    public function show(Request $request, Team $team): Response
    {
        abort_unless($request->user()->isTeamAdmin($team), 403);
        abort_unless(class_exists(MailboxKitService::class), 404);

        $domain = $team->emailDomain;

        return Inertia::render('settings/teams/email-domain', [
            'team' => $team,
            'defaultDomain' => config('mailboxkit.email_domain'),
            'domain' => $domain ? $this->serializeDomain($domain) : null,
        ]);
    }

    public function store(RegisterEmailDomainRequest $request, Team $team, MailboxKitService $mailboxKit): RedirectResponse
    {
        abort_unless($request->user()->isTeamAdmin($team), 403);

        if ($team->emailDomain) {
            return back()->withErrors(['name' => 'This team already has a custom domain. Remove it first to register a new one.']);
        }

        $name = $request->validated('name');

        try {
            $registered = $mailboxKit->createDomain($name);
            $domainId = (string) ($registered['data']['id'] ?? '');

            if ($domainId === '') {
                throw new \RuntimeException('MailboxKit did not return a domain id.');
            }

            $dnsRecords = $mailboxKit->getDomainDnsRecords($domainId)['data'] ?? [];

            $team->emailDomain()->create([
                'mailboxkit_domain_id' => $domainId,
                'name' => $name,
                'dns_records' => $dnsRecords,
                'last_checked_at' => now(),
            ]);
        } catch (RequestException $e) {
            Log::warning('MailboxKit createDomain failed', [
                'team_id' => $team->id,
                'name' => $name,
                'status' => $e->response->status(),
                'body' => $e->response->body(),
            ]);

            return back()->withErrors([
                'name' => 'MailboxKit rejected this domain: '.$this->extractMailboxKitError($e),
            ]);
        }

        return back();
    }

    public function verify(Request $request, Team $team, MailboxKitService $mailboxKit): RedirectResponse
    {
        abort_unless($request->user()->isTeamAdmin($team), 403);

        $domain = $team->emailDomain;
        abort_unless($domain, 404);

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
        } catch (RequestException $e) {
            Log::warning('MailboxKit verifyDomain failed', [
                'team_id' => $team->id,
                'domain_id' => $domain->mailboxkit_domain_id,
                'status' => $e->response->status(),
                'body' => $e->response->body(),
            ]);

            return back()->withErrors([
                'verify' => 'Verification check failed: '.$this->extractMailboxKitError($e),
            ]);
        }

        return back();
    }

    public function destroy(Request $request, Team $team, MailboxKitService $mailboxKit): RedirectResponse
    {
        abort_unless($request->user()->isTeamAdmin($team), 403);

        $domain = $team->emailDomain;
        abort_unless($domain, 404);

        try {
            $mailboxKit->deleteDomain($domain->mailboxkit_domain_id);
        } catch (RequestException $e) {
            Log::warning('MailboxKit deleteDomain failed; removing local record anyway', [
                'team_id' => $team->id,
                'domain_id' => $domain->mailboxkit_domain_id,
                'status' => $e->response->status(),
            ]);
        }

        $domain->delete();

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeDomain(TeamEmailDomain $domain): array
    {
        return [
            'id' => $domain->id,
            'name' => $domain->name,
            'is_verified' => $domain->is_verified,
            'mx_verified' => $domain->mx_verified,
            'spf_verified' => $domain->spf_verified,
            'dkim_verified' => $domain->dkim_verified,
            'dmarc_verified' => $domain->dmarc_verified,
            'dns_records' => $domain->dns_records,
            'verified_at' => $domain->verified_at,
            'last_checked_at' => $domain->last_checked_at,
        ];
    }

    private function extractMailboxKitError(RequestException $e): string
    {
        $body = $e->response->json();

        return is_array($body)
            ? (string) ($body['message'] ?? $e->response->body())
            : (string) $e->response->body();
    }
}
