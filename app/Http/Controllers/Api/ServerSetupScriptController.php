<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Services\Scripts\ServerSetupScriptService;
use App\Services\SignedScriptUrlService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ServerSetupScriptController extends Controller
{
    /**
     * Serve the server setup script after validating the HMAC signature.
     */
    public function show(Request $request, Server $server, SignedScriptUrlService $urlService): Response
    {
        $request->validate([
            'expires_at' => ['required', 'integer'],
            'signature' => ['required', 'string'],
        ]);

        if (! $urlService->verify(
            'server-setup',
            [$server->id],
            $request->input('expires_at'),
            $request->input('signature'),
        )) {
            abort(403, 'Invalid or expired signature.');
        }

        $scriptService = app(ServerSetupScriptService::class);

        return response($scriptService->generateScript($server), 200)
            ->header('Content-Type', 'text/plain');
    }
}
