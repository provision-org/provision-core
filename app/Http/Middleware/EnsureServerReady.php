<?php

namespace App\Http\Middleware;

use App\Enums\ServerStatus;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureServerReady
{
    public function handle(Request $request, Closure $next): Response
    {
        $team = $request->user()?->currentTeam;

        if (! $team) {
            return $next($request);
        }

        $server = $team->server;

        if ($server && $server->status !== ServerStatus::Running) {
            return redirect()->route('teams.provisioning', $team);
        }

        return $next($request);
    }
}
