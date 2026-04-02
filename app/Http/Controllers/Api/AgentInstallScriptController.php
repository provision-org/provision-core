<?php

namespace App\Http\Controllers\Api;

use App\Enums\AgentStatus;
use App\Events\AgentUpdatedEvent;
use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Services\AgentInstallScriptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class AgentInstallScriptController extends Controller
{
    /**
     * Serve the agent install script after validating the HMAC signature.
     */
    public function show(Request $request, Agent $agent, AgentInstallScriptService $scriptService): Response
    {
        $request->validate([
            'expires_at' => ['required', 'integer'],
            'signature' => ['required', 'string'],
        ]);

        $expectedSignature = hash_hmac(
            'sha256',
            "install|{$agent->id}|{$request->input('expires_at')}",
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

    /**
     * Handle the agent ready/error callback from the install script.
     */
    public function callback(Request $request): JsonResponse
    {
        $request->validate([
            'agent_id' => ['required', 'string', 'ulid'],
            'status' => ['required', 'string', 'in:ready,error'],
            'expires_at' => ['required', 'integer'],
            'signature' => ['required', 'string'],
        ]);

        $expectedSignature = hash_hmac(
            'sha256',
            "callback|{$request->input('agent_id')}|{$request->input('expires_at')}",
            config('app.key'),
        );

        if (! hash_equals($expectedSignature, $request->input('signature'))) {
            abort(403, 'Invalid signature.');
        }

        if (now()->timestamp > (int) $request->input('expires_at')) {
            abort(403, 'Signature expired.');
        }

        $agent = Agent::findOrFail($request->input('agent_id'));

        if ($request->input('status') === 'ready') {
            $agent->update(['status' => AgentStatus::Active]);

            try {
                broadcast(new AgentUpdatedEvent($agent));
            } catch (\Throwable $e) {
                Log::warning('Failed to broadcast AgentUpdatedEvent', ['agent_id' => $agent->id, 'error' => $e->getMessage()]);
            }

            $agent->server->events()->create([
                'event' => 'agent_install_complete',
                'payload' => ['agent_id' => $agent->id],
            ]);
        } else {
            $agent->update(['status' => AgentStatus::Error]);

            try {
                broadcast(new AgentUpdatedEvent($agent));
            } catch (\Throwable $e) {
                Log::warning('Failed to broadcast AgentUpdatedEvent', ['agent_id' => $agent->id, 'error' => $e->getMessage()]);
            }

            $agent->server->events()->create([
                'event' => 'agent_install_error',
                'payload' => ['agent_id' => $agent->id],
            ]);
        }

        return response()->json(['status' => 'ok']);
    }
}
