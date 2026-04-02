<?php

namespace App\Http\Controllers\Api;

use App\Enums\ServerStatus;
use App\Http\Controllers\Controller;
use App\Jobs\SetupOpenClawOnServerJob;
use App\Models\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ServerCallbackController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'server_id' => ['required', 'string', 'ulid'],
            'status' => ['required', 'string', 'in:ready,error,progress'],
            'signature' => ['required', 'string'],
            'expires_at' => ['required', 'integer'],
            'step' => ['required_if:status,progress', 'string', 'max:100'],
        ]);

        $expectedSignature = hash_hmac(
            'sha256',
            $request->input('server_id').'|'.$request->input('expires_at'),
            config('app.key'),
        );

        if (! hash_equals($expectedSignature, $request->input('signature'))) {
            abort(403, 'Invalid signature.');
        }

        if (now()->timestamp > (int) $request->input('expires_at')) {
            abort(403, 'Signature expired.');
        }

        $server = Server::findOrFail($request->input('server_id'));

        if ($request->input('status') === 'progress') {
            $server->events()->create([
                'event' => 'cloud_init_progress',
                'payload' => ['step' => $request->input('step')],
            ]);
        } elseif ($request->input('status') === 'ready') {
            $server->update(['status' => ServerStatus::SetupComplete]);

            $server->events()->create([
                'event' => 'server_ready',
                'payload' => [],
            ]);

            SetupOpenClawOnServerJob::dispatch($server);
        } else {
            $server->update(['status' => ServerStatus::Error]);

            $server->events()->create([
                'event' => 'provisioning_error',
                'payload' => $request->only('error_message'),
            ]);
        }

        return response()->json(['status' => 'ok']);
    }
}
