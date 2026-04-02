<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\AgentActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AgentActivityController extends Controller
{
    public function index(Request $request): Response
    {
        $team = $request->user()->currentTeam;

        $activities = AgentActivity::query()
            ->forTeam($team->id)
            ->when($request->query('agent_id'), fn ($q, $agentId) => $q->where('agent_id', $agentId))
            ->when($request->query('type'), fn ($q, $type) => $q->ofType($type))
            ->with('agent')
            ->orderByDesc('created_at')
            ->paginate(50);

        return Inertia::render('activity/index', [
            'activities' => $activities,
        ]);
    }

    public function forAgent(Agent $agent, Request $request): JsonResponse
    {
        $team = $request->user()->currentTeam;

        abort_unless($agent->team_id === $team->id, 404);

        $activities = $agent->activities()
            ->when($request->query('type'), fn ($q, $type) => $q->ofType($type))
            ->with('agent')
            ->orderByDesc('created_at')
            ->paginate(30);

        return response()->json($activities);
    }
}
