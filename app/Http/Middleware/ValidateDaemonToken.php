<?php

namespace App\Http\Middleware;

use App\Models\Server;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateDaemonToken
{
    /**
     * Validate the daemon token from the route parameter.
     *
     * The daemon_token column uses Laravel's encrypted cast, so we cannot query
     * by plaintext value. Instead we load servers that have a daemon token set
     * and compare after Eloquent decrypts the value.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->route('token');

        if (! $token) {
            abort(401, 'Missing daemon token.');
        }

        $server = Server::query()
            ->whereNotNull('daemon_token')
            ->get()
            ->first(fn (Server $s) => $s->daemon_token === $token);

        if (! $server) {
            abort(401, 'Invalid daemon token.');
        }

        $request->merge(['daemon_server' => $server]);

        return $next($request);
    }
}
