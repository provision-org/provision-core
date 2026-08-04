<?php

namespace App\Http\Middleware;

use App\Models\Server;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateDaemonToken
{
    /**
     * Validate either the current server-scoped bearer-token route or the
     * legacy token-in-path route retained for provisiond v0.3 compatibility.
     *
     * The daemon_token column uses Laravel's encrypted cast, so we cannot query
     * by plaintext value. The bearer-token route includes the server ULID so it
     * decrypts exactly one row; only the legacy route must scan existing tokens.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $serverId = $request->route('server');
        $server = $serverId !== null
            ? $this->serverForBearerToken($request, $serverId)
            : $this->serverForLegacyPathToken($request);

        $request->attributes->set('daemon_server', $server);

        return $next($request);
    }

    private function serverForBearerToken(Request $request, mixed $serverId): Server
    {
        $token = $request->bearerToken();
        if (! is_string($token) || $token === '') {
            abort(401, 'Missing daemon token.');
        }

        $routeServerId = $serverId instanceof Server ? $serverId->getKey() : $serverId;
        $server = is_string($routeServerId) && $routeServerId !== ''
            ? Server::query()->find($routeServerId)
            : null;
        $expectedToken = $server?->daemon_token;

        if (! $server
            || ! is_string($expectedToken)
            || $expectedToken === ''
            || ! hash_equals($expectedToken, $token)) {
            abort(401, 'Invalid daemon token.');
        }

        return $server;
    }

    private function serverForLegacyPathToken(Request $request): Server
    {
        $token = $request->route('token');
        if (! is_string($token) || $token === '') {
            abort(401, 'Missing daemon token.');
        }

        $server = Server::query()
            ->whereNotNull('daemon_token')
            ->get()
            ->first(function (Server $candidate) use ($token): bool {
                $expectedToken = $candidate->daemon_token;

                return is_string($expectedToken)
                    && $expectedToken !== ''
                    && hash_equals($expectedToken, $token);
            });

        if (! $server) {
            abort(401, 'Invalid daemon token.');
        }

        return $server;
    }
}
