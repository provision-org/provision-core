<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\Goal;
use App\Models\Task;
use App\Models\Team;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        $team = $request->user()->currentTeam;

        $query = $team->auditLogs();

        if ($request->filled('actor_type')) {
            $query->where('actor_type', $request->string('actor_type'));
        }

        if ($request->filled('action')) {
            $query->where('action', $request->string('action'));
        }

        if ($request->filled('target_type')) {
            $query->where('target_type', $request->string('target_type'));
        }

        if ($request->filled('from')) {
            $query->where('created_at', '>=', $request->date('from'));
        }

        if ($request->filled('to')) {
            $query->where('created_at', '<=', $request->date('to')->endOfDay());
        }

        $entries = $query->orderByDesc('created_at')->paginate(50);

        // Enrich entries with human-readable target names
        $entries->getCollection()->transform(function ($entry) {
            $entry->target_name = $this->resolveTargetName($entry->target_type, $entry->target_id, $entry->payload);

            return $entry;
        });

        return Inertia::render('company/audit/index', [
            'team' => $team,
            'entries' => $entries,
            'filters' => $request->only(['actor_type', 'action', 'target_type', 'from', 'to']),
        ]);
    }

    private function resolveTargetName(?string $targetType, ?string $targetId, ?array $payload): ?string
    {
        if (! $targetType || ! $targetId) {
            return null;
        }

        // Check if the payload already has a title
        if (! empty($payload['title'])) {
            return $payload['title'];
        }

        return match ($targetType) {
            'task' => Task::find($targetId)?->title,
            'goal' => Goal::find($targetId)?->title,
            'agent' => Agent::find($targetId)?->name,
            default => null,
        };
    }

    private function authorizeTeam(Request $request, Team $team): void
    {
        abort_unless($team->id === $request->user()->current_team_id, 403);
    }
}
