<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Services\Scripts\AgentUpdateScriptService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AgentUpdateScriptController extends Controller
{
    /**
     * Serve the agent update script after validating the HMAC signature.
     */
    public function show(Request $request, Agent $agent, AgentUpdateScriptService $scriptService): Response
    {
        $request->validate([
            'expires_at' => ['required', 'integer'],
            'signature' => ['required', 'string'],
        ]);

        $expectedSignature = hash_hmac(
            'sha256',
            "agent-update|{$agent->id}|{$request->input('expires_at')}",
            config('app.key'),
        );

        if (! hash_equals($expectedSignature, $request->input('signature'))) {
            abort(403, 'Invalid signature.');
        }

        if (now()->timestamp > (int) $request->input('expires_at')) {
            abort(403, 'Signature expired.');
        }

        $script = match ($agent->harness_type) {
            \App\Enums\HarnessType::Hermes => $scriptService->generateHermesScript($agent),
            default => $scriptService->generateOpenClawScript($agent),
        };

        return response($script, 200)->header('Content-Type', 'text/plain');
    }
}
