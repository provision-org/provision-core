<?php

namespace App\Http\Controllers;

use App\Enums\ActorType;
use App\Enums\GoalStatus;
use App\Http\Requests\Governance\StoreGoalRequest;
use App\Http\Requests\Governance\UpdateGoalRequest;
use App\Models\Goal;
use App\Models\Team;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GoalController extends Controller
{
    public function __construct(
        private readonly AuditService $audit,
    ) {}

    public function index(Request $request, Team $team): Response
    {
        $this->authorizeTeam($request, $team);

        $query = $team->goals()->with(['ownerAgent', 'children', 'parent']);

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('parent_id')) {
            $query->where('parent_id', $request->string('parent_id'));
        } else {
            $query->whereNull('parent_id');
        }

        $goals = $query->orderByDesc('created_at')->get();

        return Inertia::render('governance/goals/index', [
            'goals' => $goals,
            'filters' => $request->only(['status', 'parent_id']),
        ]);
    }

    public function store(StoreGoalRequest $request, Team $team): RedirectResponse
    {
        $this->authorizeTeam($request, $team);

        $goal = $team->goals()->create([
            ...$request->validated(),
            'status' => GoalStatus::Active,
            'progress_pct' => 0,
        ]);

        $this->audit->log(
            teamId: $team->id,
            actorType: ActorType::User,
            actorId: $request->user()->id,
            action: 'goal.created',
            targetType: 'goal',
            targetId: $goal->id,
            payload: ['title' => $goal->title],
        );

        return redirect()->route('governance.goals.index', $team)
            ->with('success', 'Goal created.');
    }

    public function update(UpdateGoalRequest $request, Goal $goal): RedirectResponse
    {
        $this->authorizeTeam($request, $goal->team);

        $goal->update($request->validated());

        $goal->update(['progress_pct' => $goal->calculateProgress()]);

        $this->audit->log(
            teamId: $goal->team_id,
            actorType: ActorType::User,
            actorId: $request->user()->id,
            action: 'goal.updated',
            targetType: 'goal',
            targetId: $goal->id,
            payload: $request->validated(),
        );

        return redirect()->route('governance.goals.index', $goal->team_id)
            ->with('success', 'Goal updated.');
    }

    public function destroy(Request $request, Goal $goal): RedirectResponse
    {
        $this->authorizeTeam($request, $goal->team);

        $goal->update(['status' => GoalStatus::Abandoned]);

        $this->audit->log(
            teamId: $goal->team_id,
            actorType: ActorType::User,
            actorId: $request->user()->id,
            action: 'goal.abandoned',
            targetType: 'goal',
            targetId: $goal->id,
        );

        return redirect()->route('governance.goals.index', $goal->team_id)
            ->with('success', 'Goal abandoned.');
    }

    private function authorizeTeam(Request $request, Team $team): void
    {
        abort_unless($team->id === $request->user()->current_team_id, 403);
    }
}
