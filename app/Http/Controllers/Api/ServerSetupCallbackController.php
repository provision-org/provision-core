<?php

namespace App\Http\Controllers\Api;

use App\Enums\ServerStatus;
use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Services\SignedScriptUrlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ServerSetupCallbackController extends Controller
{
    /**
     * Handle callbacks from the server setup script.
     *
     * The setup script fires progress callbacks at each major step,
     * and a final 'ready' or 'error' callback when done.
     */
    public function __invoke(Request $request, SignedScriptUrlService $urlService): JsonResponse
    {
        $request->validate([
            'server_id' => ['required', 'string', 'ulid'],
            'status' => ['required', 'string', 'in:ready,error,progress'],
            'expires_at' => ['required', 'integer'],
            'signature' => ['required', 'string'],
            'step' => ['nullable', 'string', 'max:100'],
            'vnc_password' => ['nullable', 'string', 'max:100'],
            'error_message' => ['nullable', 'string', 'max:1000'],
            'context' => ['nullable', 'string', 'max:2000'],
        ]);

        if (! $urlService->verify(
            'server-setup-callback',
            [$request->input('server_id')],
            $request->input('expires_at'),
            $request->input('signature'),
        )) {
            abort(403, 'Invalid or expired signature.');
        }

        $server = Server::findOrFail($request->input('server_id'));

        match ($request->input('status')) {
            'progress' => $this->handleProgress($server, $request),
            'ready' => $this->handleReady($server, $request),
            'error' => $this->handleError($server, $request),
        };

        return response()->json(['status' => 'ok']);
    }

    private function handleProgress(Server $server, Request $request): void
    {
        $step = $request->input('step', 'unknown');

        $server->events()->create([
            'event' => 'setup_progress',
            'payload' => ['step' => $step],
        ]);

        // Store VNC password if provided in the callback
        if ($request->filled('vnc_password') && ! $server->vnc_password) {
            $server->forceFill(['vnc_password' => $request->input('vnc_password')])->save();
        }
    }

    private function handleReady(Server $server, Request $request): void
    {
        $server->update([
            'status' => ServerStatus::Running,
            'provisioned_at' => now(),
        ]);

        $server->events()->create([
            'event' => 'setup_complete',
            'payload' => [],
        ]);

        Log::info("Server {$server->id} setup completed via script callback");
    }

    private function handleError(Server $server, Request $request): void
    {
        $server->update(['status' => ServerStatus::Error]);

        $errorMessage = $request->input('error_message', 'Unknown error');
        $context = $request->input('context');

        $server->events()->create([
            'event' => 'setup_failed',
            'payload' => array_filter([
                'error' => $errorMessage,
                'context' => $context,
            ]),
        ]);

        Log::error("Server {$server->id} setup failed: {$errorMessage}", [
            'context' => $context ? urldecode($context) : null,
        ]);
    }
}
