<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class UsageController extends Controller
{
    public function index(Request $request, Team $team): Response
    {
        $this->authorizeTeam($request, $team);

        $byAgent = $team->usageEvents()
            ->select('agent_id', DB::raw('SUM(input_tokens) as total_input'), DB::raw('SUM(output_tokens) as total_output'), DB::raw('COUNT(*) as event_count'))
            ->groupBy('agent_id')
            ->with('agent:id,name')
            ->get();

        $byDay = $team->usageEvents()
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('SUM(input_tokens) as total_input'), DB::raw('SUM(output_tokens) as total_output'), DB::raw('COUNT(*) as event_count'))
            ->groupBy('date')
            ->orderByDesc('date')
            ->limit(30)
            ->get();

        $totals = $team->usageEvents()
            ->select(DB::raw('SUM(input_tokens) as total_input'), DB::raw('SUM(output_tokens) as total_output'), DB::raw('COUNT(*) as event_count'))
            ->first();

        return Inertia::render('governance/usage/index', [
            'byAgent' => $byAgent,
            'byDay' => $byDay,
            'totals' => $totals,
        ]);
    }

    public function forAgent(Request $request, Agent $agent): JsonResponse
    {
        abort_unless($agent->team_id === $request->user()->current_team_id, 403);

        $usage = $agent->usageEvents()
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('SUM(input_tokens) as total_input'), DB::raw('SUM(output_tokens) as total_output'), DB::raw('COUNT(*) as event_count'))
            ->groupBy('date')
            ->orderByDesc('date')
            ->limit(30)
            ->get();

        $totals = $agent->usageEvents()
            ->select(DB::raw('SUM(input_tokens) as total_input'), DB::raw('SUM(output_tokens) as total_output'), DB::raw('COUNT(*) as event_count'))
            ->first();

        return response()->json([
            'agent_id' => $agent->id,
            'agent_name' => $agent->name,
            'byDay' => $usage,
            'totals' => $totals,
        ]);
    }

    private function authorizeTeam(Request $request, Team $team): void
    {
        abort_unless($team->id === $request->user()->current_team_id, 403);
    }
}
