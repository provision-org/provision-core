<?php

namespace App\Http\Middleware;

use App\Models\AgentApiToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateAgentToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();

        if (! $bearer) {
            return response()->json(['message' => 'Missing API token.'], 401);
        }

        $apiToken = AgentApiToken::findByToken($bearer);

        if (! $apiToken) {
            return response()->json(['message' => 'Invalid API token.'], 401);
        }

        $apiToken->update(['last_used_at' => now()]);

        $request->merge([
            'authenticated_agent' => $apiToken->agent,
            'authenticated_team' => $apiToken->team,
        ]);

        return $next($request);
    }
}
