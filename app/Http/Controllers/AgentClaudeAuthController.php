<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Services\ClaudeAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentClaudeAuthController extends Controller
{
    public function __construct(private ClaudeAuthService $claude) {}

    public function store(Request $request, Agent $agent): JsonResponse
    {
        $this->authorizeAgent($request, $agent);

        $validated = $request->validate([
            'setup_token' => ['required', 'string', 'min:20'],
        ]);

        if (! $agent->server || $agent->server->status->value !== 'running') {
            return response()->json([
                'message' => 'Agent server is not running yet.',
            ], 409);
        }

        try {
            $result = $this->claude->connect($agent, $validated['setup_token']);

            return response()->json($result);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Failed to connect Claude subscription: '.$e->getMessage(),
            ], 502);
        }
    }

    public function show(Request $request, Agent $agent): JsonResponse
    {
        $this->authorizeAgent($request, $agent);

        try {
            return response()->json($this->claude->status($agent));
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['state' => 'disconnected'], 200);
        }
    }

    public function destroy(Request $request, Agent $agent): JsonResponse
    {
        $this->authorizeAgent($request, $agent);

        $this->claude->disconnect($agent);

        return response()->json(['state' => 'disconnected']);
    }

    private function authorizeAgent(Request $request, Agent $agent): void
    {
        $team = $request->user()->currentTeam;
        abort_unless($agent->team_id === $team->id, 404);
        abort_unless($request->user()->isTeamAdmin($team), 403);
    }
}
