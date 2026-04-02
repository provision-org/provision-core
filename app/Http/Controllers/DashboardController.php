<?php

namespace App\Http\Controllers;

use App\Models\AgentActivity;
use App\Models\AgentDailyStat;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $team = $request->user()->currentTeam;

        $activities = AgentActivity::query()
            ->forTeam($team->id)
            ->with('agent:id,name,avatar_path')
            ->latest()
            ->limit(30)
            ->get()
            ->map(fn (AgentActivity $a) => [
                'id' => $a->id,
                'agent_id' => $a->agent_id,
                'agent_name' => $a->agent?->name,
                'type' => $a->type,
                'channel' => $a->channel,
                'summary' => $a->summary,
                'created_at' => $a->created_at->toISOString(),
            ]);

        $agents = $team->agents()->get([
            'id', 'name', 'status', 'avatar_path',
            'stats_tokens_input', 'stats_tokens_output',
            'stats_total_sessions', 'stats_total_messages',
            'stats_last_active_at',
        ]);

        $taskCounts = [
            'in_progress' => Task::query()->where('team_id', $team->id)->where('status', 'in_progress')->count(),
            'in_review' => Task::query()->where('team_id', $team->id)->where('status', 'in_review')->count(),
            'blocked' => Task::query()->where('team_id', $team->id)
                ->whereHas('notes', fn ($q) => $q->where('body', 'like', 'Blocked:%'))
                ->whereNotIn('status', ['done'])
                ->count(),
            'total' => Task::query()->where('team_id', $team->id)->count(),
        ];

        $tokenStats = [
            'total_input' => $agents->sum('stats_tokens_input'),
            'total_output' => $agents->sum('stats_tokens_output'),
            'total_sessions' => $agents->sum('stats_total_sessions'),
            'total_messages' => $agents->sum('stats_total_messages'),
        ];

        return Inertia::render('dashboard', [
            'activities' => $activities,
            'agents' => $agents,
            'taskCounts' => $taskCounts,
            'tokenStats' => $tokenStats,
            'currentPlan' => $team->plan instanceof \BackedEnum ? $team->plan->value : $team->plan,
        ]);
    }

    public function usageChart(Request $request): JsonResponse
    {
        $team = $request->user()->currentTeam;
        $agentIds = $team->agents()->pluck('id');

        if ($agentIds->isEmpty()) {
            return response()->json([]);
        }

        $days = min((int) $request->query('days', '30'), 90);
        $startDate = now()->subDays($days)->toDateString();

        // Get daily snapshots aggregated across all agents
        $snapshots = AgentDailyStat::query()
            ->whereIn('agent_id', $agentIds)
            ->where('date', '>=', $startDate)
            ->selectRaw('date, SUM(cumulative_tokens_input) as sum_input, SUM(cumulative_tokens_output) as sum_output, SUM(cumulative_messages) as sum_messages, SUM(cumulative_sessions) as sum_sessions')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // Baseline: last snapshot before window, aggregated
        $baseline = AgentDailyStat::query()
            ->whereIn('agent_id', $agentIds)
            ->where('date', '<', $startDate)
            ->selectRaw('SUM(cumulative_tokens_input) as sum_input, SUM(cumulative_tokens_output) as sum_output, SUM(cumulative_messages) as sum_messages, SUM(cumulative_sessions) as sum_sessions')
            ->first();

        $prevInput = (int) ($baseline?->sum_input ?? 0);
        $prevOutput = (int) ($baseline?->sum_output ?? 0);
        $prevMessages = (int) ($baseline?->sum_messages ?? 0);
        $prevSessions = (int) ($baseline?->sum_sessions ?? 0);

        $data = $snapshots->map(function ($stat) use (&$prevInput, &$prevOutput, &$prevMessages, &$prevSessions): array {
            $input = (int) $stat->sum_input;
            $output = (int) $stat->sum_output;
            $messages = (int) $stat->sum_messages;
            $sessions = (int) $stat->sum_sessions;

            $row = [
                'date' => $stat->date,
                'tokens_input' => max(0, $input - $prevInput),
                'tokens_output' => max(0, $output - $prevOutput),
                'messages' => max(0, $messages - $prevMessages),
                'sessions' => max(0, $sessions - $prevSessions),
            ];

            $prevInput = $input;
            $prevOutput = $output;
            $prevMessages = $messages;
            $prevSessions = $sessions;

            return $row;
        })->values();

        return response()->json($data);
    }
}
