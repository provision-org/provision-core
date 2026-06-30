<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class CaddyController extends Controller
{
    /**
     * On-demand TLS gate for Caddy.
     *
     * Caddy calls this before issuing a certificate for a host. We only allow
     * issuance for artifact subdomains that map to an agent with a live
     * artifact, which prevents the open-ended cert issuance an unguarded
     * on-demand-TLS setup would otherwise permit.
     */
    public function ask(Request $request): Response
    {
        $domain = (string) $request->query('domain');
        $artifactDomain = config('cloudflare.artifact_domain');

        if ($domain === '' || ! $artifactDomain || ! str_ends_with($domain, ".{$artifactDomain}")) {
            abort(404);
        }

        $slug = Str::beforeLast($domain, ".{$artifactDomain}");

        $agent = Agent::query()->where('slug', $slug)->first();

        if (! $agent || ! $agent->artifacts()->where('status', 'live')->exists()) {
            abort(404);
        }

        return response('ok', 200);
    }
}
