<?php

namespace App\Http\Controllers\Api;

use App\Enums\AgentMode;
use App\Enums\AgentStatus;
use App\Events\AgentActivityEvent;
use App\Events\TaskStatusChangedEvent;
use App\Http\Controllers\Controller;
use App\Jobs\NotifyAgentAboutTaskJob;
use App\Models\Agent;
use App\Models\AgentActivity;
use App\Models\Task;
use App\Models\TaskNote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $team = $request->input('authenticated_team');
        $agent = $request->input('authenticated_agent');

        $query = Task::query()
            ->forTeam($team->id)
            ->when($request->query('status'), fn ($q, $status) => $q->byStatus($status))
            ->when($request->query('priority'), fn ($q, $priority) => $q->byPriority($priority))
            ->when($request->has('assigned'), fn ($q) => $q->where('agent_id', $agent->id))
            ->with('agent', 'notes')
            ->orderBy('sort_order')
            ->orderBy('created_at');

        $limit = min((int) $request->query('limit', '20'), 100);

        return response()->json($query->limit($limit)->get());
    }

    public function next(Request $request): JsonResponse
    {
        $team = $request->input('authenticated_team');

        $task = Task::query()
            ->forTeam($team->id)
            ->byStatus('up_next')
            ->whereNull('agent_id')
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->first();

        if (! $task) {
            return response()->json(['message' => 'No tasks available.'], 404);
        }

        return response()->json($task);
    }

    public function teamAgents(Request $request): JsonResponse
    {
        $team = $request->input('authenticated_team');

        return response()->json(
            Agent::query()
                ->where('team_id', $team->id)
                ->where('status', AgentStatus::Active)
                ->get(['id', 'name', 'role', 'harness_agent_id'])
        );
    }

    public function show(Task $task, Request $request): JsonResponse
    {
        $team = $request->input('authenticated_team');

        abort_unless($task->team_id === $team->id, 404);

        return response()->json($task->load('notes'));
    }

    public function store(Request $request): JsonResponse
    {
        $team = $request->input('authenticated_team');
        $agent = $request->input('authenticated_agent');

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'priority' => ['nullable', 'string', 'in:none,low,medium,high'],
            'tags' => ['nullable', 'array'],
            'assign_to' => ['nullable', 'string'],
        ]);

        // Resolve assign_to — can be agent name, @handle, ID, or harness_agent_id
        $assignToAgent = null;
        if (! empty($validated['assign_to'])) {
            $assignTo = ltrim($validated['assign_to'], '@');
            $assignToAgent = Agent::query()
                ->where('team_id', $team->id)
                ->where(function ($q) use ($assignTo) {
                    $q->where('name', $assignTo)
                        ->orWhere('handle', $assignTo)
                        ->orWhere('id', $assignTo)
                        ->orWhere('harness_agent_id', $assignTo);
                })
                ->first();
        }

        $isAssigningToOther = $assignToAgent && $assignToAgent->id !== $agent->id;

        if ($isAssigningToOther && ! $agent->delegation_enabled) {
            return response()->json(['message' => 'This agent is not permitted to delegate tasks.'], 403);
        }

        // Workforce agents are driven by provisiond which polls for 'todo' status
        $assignedStatus = match (true) {
            ! $isAssigningToOther => 'in_progress',
            $assignToAgent->agent_mode === AgentMode::Workforce => 'todo',
            default => 'up_next',
        };

        $task = Task::create([
            'team_id' => $team->id,
            'agent_id' => $isAssigningToOther ? $assignToAgent->id : $agent->id,
            'created_by_type' => 'agent',
            'created_by_id' => $agent->id,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'priority' => $validated['priority'] ?? 'none',
            'tags' => $validated['tags'] ?? null,
            'status' => $assignedStatus,
            'delegated_by' => $isAssigningToOther ? $agent->id : null,
        ]);

        $activity = AgentActivity::create([
            'agent_id' => $agent->id,
            'type' => 'task_created',
            'summary' => $isAssigningToOther
                ? "Created task for {$assignToAgent->name}: {$task->title}"
                : "Created task: {$task->title}",
        ]);

        AgentActivityEvent::dispatch($activity);

        // Notify the assigned agent if it's a different agent
        if ($isAssigningToOther && $assignToAgent->status === AgentStatus::Active) {
            $assignActivity = AgentActivity::create([
                'agent_id' => $assignToAgent->id,
                'type' => 'task_assigned',
                'summary' => "Assigned to task by {$agent->name}: {$task->title}",
            ]);

            AgentActivityEvent::dispatch($assignActivity);
            NotifyAgentAboutTaskJob::dispatch($assignToAgent, $task);
        }

        return response()->json($task, 201);
    }

    public function claim(Task $task, Request $request): JsonResponse
    {
        $team = $request->input('authenticated_team');
        $agent = $request->input('authenticated_agent');

        abort_unless($task->team_id === $team->id, 404);

        $task->update([
            'agent_id' => $agent->id,
            'status' => 'in_progress',
        ]);

        $activity = AgentActivity::create([
            'agent_id' => $agent->id,
            'type' => 'task_claimed',
            'summary' => "Claimed task: {$task->title}",
        ]);

        AgentActivityEvent::dispatch($activity);

        return response()->json($task->fresh());
    }

    public function unclaim(Task $task, Request $request): JsonResponse
    {
        $team = $request->input('authenticated_team');
        $agent = $request->input('authenticated_agent');

        abort_unless($task->team_id === $team->id, 404);

        $task->update([
            'agent_id' => null,
            'status' => 'up_next',
        ]);

        $activity = AgentActivity::create([
            'agent_id' => $agent->id,
            'type' => 'task_unclaimed',
            'summary' => "Released task: {$task->title}",
        ]);

        AgentActivityEvent::dispatch($activity);

        return response()->json($task->fresh());
    }

    public function complete(Task $task, Request $request): JsonResponse
    {
        $team = $request->input('authenticated_team');
        $agent = $request->input('authenticated_agent');

        abort_unless($task->team_id === $team->id, 404);

        $oldStatus = $task->status;

        $task->update([
            'status' => 'done',
            'completed_at' => now(),
        ]);

        $activity = AgentActivity::create([
            'agent_id' => $agent->id,
            'type' => 'task_completed',
            'summary' => "Completed task: {$task->title}",
        ]);

        AgentActivityEvent::dispatch($activity);

        if ($oldStatus !== 'done') {
            event(new TaskStatusChangedEvent($task->load('agent'), $oldStatus, 'done'));
        }

        return response()->json($task->fresh());
    }

    public function block(Task $task, Request $request): JsonResponse
    {
        $team = $request->input('authenticated_team');
        $agent = $request->input('authenticated_agent');

        abort_unless($task->team_id === $team->id, 404);

        $validated = $request->validate([
            'reason' => ['required', 'string'],
        ]);

        $oldStatus = $task->status;

        TaskNote::create([
            'task_id' => $task->id,
            'author_type' => 'agent',
            'author_id' => $agent->id,
            'body' => "Blocked: {$validated['reason']}",
        ]);

        $task->update(['status' => 'blocked']);

        $activity = AgentActivity::create([
            'agent_id' => $agent->id,
            'type' => 'task_blocked',
            'summary' => "Blocked task: {$task->title} — {$validated['reason']}",
        ]);

        AgentActivityEvent::dispatch($activity);

        if ($oldStatus !== 'blocked') {
            event(new TaskStatusChangedEvent($task->load('agent'), $oldStatus, 'blocked'));
        }

        return response()->json($task->fresh()->load('notes'));
    }

    public function addNote(Task $task, Request $request): JsonResponse
    {
        $team = $request->input('authenticated_team');
        $agent = $request->input('authenticated_agent');

        abort_unless($task->team_id === $team->id, 404);

        $validated = $request->validate([
            'body' => ['required', 'string'],
        ]);

        $note = TaskNote::create([
            'task_id' => $task->id,
            'author_type' => 'agent',
            'author_id' => $agent->id,
            'body' => $validated['body'],
        ]);

        return response()->json($note, 201);
    }

    public function update(Task $task, Request $request): JsonResponse
    {
        $team = $request->input('authenticated_team');
        $agent = $request->input('authenticated_agent');

        abort_unless($task->team_id === $team->id, 404);

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'priority' => ['sometimes', 'string', 'in:none,low,medium,high'],
            'status' => ['sometimes', 'string', 'in:inbox,todo,up_next,in_progress,in_review,blocked,done,cancelled,failed'],
            'tags' => ['nullable', 'array'],
        ]);

        $oldStatus = $task->status;

        $task->update($validated);

        if (isset($validated['status']) && $validated['status'] !== $oldStatus) {
            $activity = AgentActivity::create([
                'agent_id' => $agent->id,
                'type' => 'task_status_changed',
                'summary' => "Changed task \"{$task->title}\" status to {$validated['status']}",
            ]);

            AgentActivityEvent::dispatch($activity);
            event(new TaskStatusChangedEvent($task->load('agent'), $oldStatus, $validated['status']));
        }

        return response()->json($task->fresh());
    }
}
