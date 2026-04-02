<?php

namespace App\Http\Controllers;

use App\Enums\AgentStatus;
use App\Events\AgentActivityEvent;
use App\Http\Requests\CreateTaskRequest;
use App\Http\Requests\UpdateTaskRequest;
use App\Jobs\NotifyAgentAboutTaskJob;
use App\Models\Agent;
use App\Models\AgentActivity;
use App\Models\Task;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TaskBoardController extends Controller
{
    public function index(Request $request): Response
    {
        $team = $request->user()->currentTeam;

        $tasks = Task::query()
            ->forTeam($team->id)
            ->with(['agent', 'notes' => fn ($q) => $q->latest()->limit(3)])
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get();

        $agents = $team->agents()->get(['id', 'name', 'avatar_path']);

        return Inertia::render('tasks/index', [
            'tasks' => $tasks,
            'agents' => $agents,
        ]);
    }

    public function store(CreateTaskRequest $request): RedirectResponse
    {
        $team = $request->user()->currentTeam;
        $validated = $request->validated();

        $task = Task::create([
            'team_id' => $team->id,
            'created_by_type' => 'user',
            'created_by_id' => $request->user()->id,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'priority' => $validated['priority'] ?? 'none',
            'agent_id' => $validated['agent_id'] ?? null,
            'tags' => $validated['tags'] ?? null,
            'status' => 'inbox',
        ]);

        if ($task->agent_id) {
            $activity = AgentActivity::create([
                'agent_id' => $task->agent_id,
                'type' => 'task_assigned',
                'summary' => "Assigned to task: {$task->title}",
            ]);

            AgentActivityEvent::dispatch($activity);

            $agent = Agent::find($task->agent_id);
            if ($agent?->status === AgentStatus::Active) {
                NotifyAgentAboutTaskJob::dispatch($agent, $task);
            }
        }

        return back();
    }

    public function update(Task $task, UpdateTaskRequest $request): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        abort_unless($task->team_id === $team->id, 404);

        $validated = $request->validated();
        $oldStatus = $task->status;
        $oldAgentId = $task->agent_id;

        $task->update($validated);

        if (isset($validated['status']) && $validated['status'] !== $oldStatus && $task->agent_id) {
            $activity = AgentActivity::create([
                'agent_id' => $task->agent_id,
                'type' => 'task_status_changed',
                'summary' => "Task \"{$task->title}\" moved to {$validated['status']}",
            ]);

            AgentActivityEvent::dispatch($activity);
        }

        if (isset($validated['agent_id']) && $validated['agent_id'] !== $oldAgentId && $validated['agent_id']) {
            $activity = AgentActivity::create([
                'agent_id' => $validated['agent_id'],
                'type' => 'task_assigned',
                'summary' => "Assigned to task: {$task->title}",
            ]);

            AgentActivityEvent::dispatch($activity);

            $agent = Agent::find($validated['agent_id']);
            if ($agent?->status === AgentStatus::Active) {
                NotifyAgentAboutTaskJob::dispatch($agent, $task);
            }
        }

        return back();
    }

    public function destroy(Task $task, Request $request): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        abort_unless($task->team_id === $team->id, 404);

        $task->delete();

        return back();
    }
}
