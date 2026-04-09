<?php

namespace App\Http\Controllers;

use App\Enums\ActorType;
use App\Events\TaskStatusChangedEvent;
use App\Http\Requests\Governance\StoreTaskRequest;
use App\Http\Requests\Governance\UpdateTaskRequest;
use App\Models\Task;
use App\Models\Team;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GovernanceTaskController extends Controller
{
    public function __construct(
        private readonly AuditService $audit,
    ) {}

    public function index(Request $request): Response
    {
        $team = $request->user()->currentTeam;

        $query = $team->tasks()->with(['agent', 'goal', 'parentTask']);

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('agent_id')) {
            $query->where('agent_id', $request->string('agent_id'));
        }

        if ($request->filled('goal_id')) {
            $query->where('goal_id', $request->string('goal_id'));
        }

        if ($request->filled('priority')) {
            $query->where('priority', $request->string('priority'));
        }

        $tasks = $query->orderByDesc('created_at')->get();

        return Inertia::render('company/tasks/index', [
            'team' => $team,
            'tasks' => $tasks,
            'filters' => $request->only(['status', 'agent_id', 'goal_id', 'priority']),
            'agents' => $team->agents()->select(['id', 'name', 'agent_mode'])->get(),
            'goals' => $team->goals()->select(['id', 'title'])->where('status', 'active')->get(),
        ]);
    }

    public function store(StoreTaskRequest $request): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        $identifier = $this->generateIdentifier($team);

        $task = $team->tasks()->create([
            ...$request->validated(),
            'identifier' => $identifier,
            'created_by_type' => 'user',
            'created_by_id' => $request->user()->id,
            'status' => 'todo',
            'sort_order' => $this->nextSortOrder($team),
        ]);

        $this->audit->log(
            teamId: $team->id,
            actorType: ActorType::User,
            actorId: $request->user()->id,
            action: 'task.created',
            targetType: 'task',
            targetId: $task->id,
            payload: ['title' => $task->title, 'identifier' => $identifier],
        );

        event(new TaskStatusChangedEvent($task->load('agent'), '', 'todo'));

        return redirect()->route('company.tasks.index')
            ->with('success', 'Task created.');
    }

    public function show(Request $request, Task $task): Response
    {
        $this->authorizeTeam($request, $task->team);

        $task->load([
            'agent',
            'assignedAgent',
            'delegatedByAgent',
            'goal',
            'parentTask',
            'subTasks.agent',
            'usageEvents',
            'workProducts',
        ]);

        $auditEntries = $task->team->auditLogs()
            ->where('target_type', 'task')
            ->where('target_id', $task->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        $goalAncestry = [];
        if ($task->goal) {
            $current = $task->goal;
            while ($current) {
                $goalAncestry[] = $current->only(['id', 'title', 'status']);
                $current = $current->parent;
            }
        }

        return Inertia::render('company/tasks/show', [
            'task' => $task,
            'auditEntries' => $auditEntries,
            'goalAncestry' => array_reverse($goalAncestry),
        ]);
    }

    public function update(UpdateTaskRequest $request, Task $task): RedirectResponse
    {
        $this->authorizeTeam($request, $task->team);

        $oldStatus = $task->status;
        $validated = $request->validated();

        if (isset($validated['status']) && $validated['status'] === 'done' && ! $task->completed_at) {
            $validated['completed_at'] = now();
        }

        $task->update($validated);

        if (isset($validated['status']) && $validated['status'] !== $oldStatus) {
            event(new TaskStatusChangedEvent($task->load('agent'), $oldStatus, $validated['status']));
        }

        $this->audit->log(
            teamId: $task->team_id,
            actorType: ActorType::User,
            actorId: $request->user()->id,
            action: 'task.updated',
            targetType: 'task',
            targetId: $task->id,
            payload: $validated,
        );

        return redirect()->route('company.tasks.show', $task)
            ->with('success', 'Task updated.');
    }

    public function destroy(Request $request, Task $task): RedirectResponse
    {
        $this->authorizeTeam($request, $task->team);

        $oldStatus = $task->status;
        $task->update(['status' => 'cancelled']);

        // Cascade cancel sub-tasks
        $task->subTasks()->where('status', '!=', 'done')->update(['status' => 'cancelled']);

        event(new TaskStatusChangedEvent($task->load('agent'), $oldStatus, 'cancelled'));

        $this->audit->log(
            teamId: $task->team_id,
            actorType: ActorType::User,
            actorId: $request->user()->id,
            action: 'task.cancelled',
            targetType: 'task',
            targetId: $task->id,
        );

        return redirect()->route('company.tasks.index')
            ->with('success', 'Task cancelled.');
    }

    /**
     * Generate a sequential task identifier like TSK-1, TSK-2, etc.
     */
    private function generateIdentifier(Team $team): string
    {
        $count = $team->tasks()->count();

        return 'TSK-'.($count + 1);
    }

    private function nextSortOrder(Team $team): int
    {
        return (int) $team->tasks()->max('sort_order') + 1;
    }

    private function authorizeTeam(Request $request, Team $team): void
    {
        abort_unless($team->id === $request->user()->current_team_id, 403);
    }
}
