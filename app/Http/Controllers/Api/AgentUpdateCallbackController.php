<?php

namespace App\Http\Controllers\Api;

use App\Enums\AgentStatus;
use App\Events\AgentUpdatedEvent;
use App\Http\Controllers\Controller;
use App\Models\Agent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AgentUpdateCallbackController extends Controller
{
    /**
     * Handle callbacks from agent update scripts.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'agent_id' => ['required', 'string', 'ulid'],
            'status' => ['required', 'string', 'in:updated,error'],
            'expires_at' => ['required', 'integer'],
            'signature' => ['required', 'string'],
            'error_message' => ['nullable', 'string', 'max:1000'],
        ]);

        $expectedSignature = hash_hmac(
            'sha256',
            "agent-update-callback|{$request->input('agent_id')}|{$request->input('expires_at')}",
            config('app.key'),
        );

        if (! hash_equals($expectedSignature, $request->input('signature'))) {
            abort(403, 'Invalid signature.');
        }

        if (now()->timestamp > (int) $request->input('expires_at')) {
            abort(403, 'Signature expired.');
        }

        $agent = Agent::findOrFail($request->input('agent_id'));

        if ($request->input('status') === 'updated') {
            $updateData = [
                'is_syncing' => false,
                'last_synced_at' => now(),
            ];

            // Transition Deploying → Active on successful update
            if ($agent->status === AgentStatus::Deploying) {
                $updateData['status'] = AgentStatus::Active;
            }

            $agent->update($updateData);

            try {
                broadcast(new AgentUpdatedEvent($agent));
            } catch (\Throwable $e) {
                Log::warning('Failed to broadcast AgentUpdatedEvent', ['agent_id' => $agent->id, 'error' => $e->getMessage()]);
            }

            Log::info("Agent {$agent->id} update completed via script callback");
        } else {
            $agent->update(['is_syncing' => false]);

            try {
                broadcast(new AgentUpdatedEvent($agent));
            } catch (\Throwable $e) {
                Log::warning('Failed to broadcast AgentUpdatedEvent', ['agent_id' => $agent->id, 'error' => $e->getMessage()]);
            }

            Log::error("Agent {$agent->id} update failed: {$request->input('error_message')}");
        }

        return response()->json(['status' => 'ok']);
    }
}
