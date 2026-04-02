<?php

namespace App\Http\Middleware;

use App\Contracts\Modules\BillingProvider;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserActivated
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->bound(BillingProvider::class)) {
            return $next($request);
        }

        if (! $request->user()) {
            return $next($request);
        }

        if (! $request->user()->isActivated() && ! $request->user()->isAdmin()) {
            return redirect()->route('waitlist');
        }

        return $next($request);
    }
}
