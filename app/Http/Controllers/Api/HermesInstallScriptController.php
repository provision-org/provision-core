<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Services\Scripts\HermesInstallScriptService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class HermesInstallScriptController extends Controller
{
    /**
     * Serve the Hermes agent install script after validating the HMAC signature.
     */
    public function show(Request $request, Agent $agent, HermesInstallScriptService $scriptService): Response
    {
        $request->validate([
            'expires_at' => ['required', 'integer'],
            'signature' => ['required', 'string'],
        ]);

        $expectedSignature = hash_hmac(
            'sha256',
            "hermes-install|{$agent->id}|{$request->input('expires_at')}",
            config('app.key'),
        );

        if (! hash_equals($expectedSignature, $request->input('signature'))) {
            abort(403, 'Invalid signature.');
        }

        if (now()->timestamp > (int) $request->input('expires_at')) {
            abort(403, 'Signature expired.');
        }

        return response($scriptService->generateScript($agent), 200)
            ->header('Content-Type', 'text/plain');
    }
}
