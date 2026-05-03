<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Services\ChatGPTAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentChatGPTAuthController extends Controller
{
    public function __construct(private ChatGPTAuthService $chatgpt) {}

    public function store(Request $request, Agent $agent): JsonResponse
    {
        $this->authorizeAgent($request, $agent);

        if (! $agent->server || $agent->server->status->value !== 'running') {
            return response()->json([
                'message' => 'Agent server is not running yet.',
            ], 409);
        }

        try {
            $result = $this->chatgpt->startDeviceCodeFlow($agent);

            return response()->json($result);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Failed to start ChatGPT auth flow: '.$e->getMessage(),
            ], 502);
        }
    }

    public function show(Request $request, Agent $agent): JsonResponse
    {
        $this->authorizeAgent($request, $agent);

        try {
            $status = $this->chatgpt->pollAuthStatus($agent);

            return response()->json($status);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['state' => 'pending'], 200);
        }
    }

    public function destroy(Request $request, Agent $agent): JsonResponse
    {
        $this->authorizeAgent($request, $agent);

        $this->chatgpt->disconnect($agent);

        return response()->json(['state' => 'disconnected']);
    }

    private function authorizeAgent(Request $request, Agent $agent): void
    {
        $team = $request->user()->currentTeam;
        abort_unless($agent->team_id === $team->id, 404);
        abort_unless($request->user()->isTeamAdmin($team), 403);
    }
}
