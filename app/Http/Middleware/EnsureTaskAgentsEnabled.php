<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate the task-agent workflow views — Task Board, Goals, Approvals and Audit
 * Log — behind config('provision.task_agents_enabled'). The routes stay
 * registered (so they can be re-enabled without a route-cache rebuild and so
 * tests can exercise them) but return 404 while the feature is off.
 */
class EnsureTaskAgentsEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('provision.task_agents_enabled'), 404);

        return $next($request);
    }
}
