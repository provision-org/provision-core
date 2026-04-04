<?php

namespace App\Http\Controllers;

use App\Enums\ActorType;
use App\Enums\ApprovalStatus;
use App\Events\ApprovalResolvedEvent;
use App\Models\Approval;
use App\Models\Team;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ApprovalController extends Controller
{
    public function __construct(
        private readonly AuditService $audit,
    ) {}

    public function index(Request $request): Response
    {
        $team = $request->user()->currentTeam;

        $query = $team->approvals()->with(['requestingAgent', 'linkedTask']);

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->string('type'));
        }

        $approvals = $query->orderByDesc('created_at')->get();

        return Inertia::render('governance/approvals/index', [
            'team' => $team,
            'approvals' => $approvals,
            'filters' => $request->only(['status', 'type']),
        ]);
    }

    public function show(Request $request, Approval $approval): Response
    {
        $this->authorizeTeam($request, $approval->team);

        $approval->load(['requestingAgent', 'linkedTask.agent', 'reviewedBy']);

        return Inertia::render('governance/approvals/show', [
            'approval' => $approval,
        ]);
    }

    public function approve(Request $request, Approval $approval): RedirectResponse
    {
        $this->authorizeTeam($request, $approval->team);

        $request->validate([
            'review_note' => ['nullable', 'string', 'max:2000'],
        ]);

        abort_unless($approval->isPending(), 422, 'Approval is not pending.');

        $approval->update([
            'status' => ApprovalStatus::Approved,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'review_note' => $request->input('review_note'),
        ]);

        // Unblock linked task if present
        if ($approval->linked_task_id) {
            $approval->linkedTask()->update(['status' => 'todo']);
        }

        $this->audit->log(
            teamId: $approval->team_id,
            actorType: ActorType::User,
            actorId: $request->user()->id,
            action: 'approval.approved',
            targetType: 'approval',
            targetId: $approval->id,
        );

        event(new ApprovalResolvedEvent($approval));

        return redirect()->route('governance.approvals.index', $approval->team_id)
            ->with('success', 'Approval granted.');
    }

    public function reject(Request $request, Approval $approval): RedirectResponse
    {
        $this->authorizeTeam($request, $approval->team);

        $request->validate([
            'review_note' => ['nullable', 'string', 'max:2000'],
        ]);

        abort_unless($approval->isPending(), 422, 'Approval is not pending.');

        $approval->update([
            'status' => ApprovalStatus::Rejected,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'review_note' => $request->input('review_note'),
        ]);

        $this->audit->log(
            teamId: $approval->team_id,
            actorType: ActorType::User,
            actorId: $request->user()->id,
            action: 'approval.rejected',
            targetType: 'approval',
            targetId: $approval->id,
        );

        event(new ApprovalResolvedEvent($approval));

        return redirect()->route('governance.approvals.index', $approval->team_id)
            ->with('success', 'Approval rejected.');
    }

    public function requestRevision(Request $request, Approval $approval): RedirectResponse
    {
        $this->authorizeTeam($request, $approval->team);

        $request->validate([
            'review_note' => ['required', 'string', 'max:2000'],
        ]);

        abort_unless($approval->isPending(), 422, 'Approval is not pending.');

        $approval->update([
            'status' => ApprovalStatus::RevisionRequested,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'review_note' => $request->input('review_note'),
        ]);

        $this->audit->log(
            teamId: $approval->team_id,
            actorType: ActorType::User,
            actorId: $request->user()->id,
            action: 'approval.revision_requested',
            targetType: 'approval',
            targetId: $approval->id,
            payload: ['note' => $request->input('review_note')],
        );

        return redirect()->route('governance.approvals.index', $approval->team_id)
            ->with('success', 'Revision requested.');
    }

    private function authorizeTeam(Request $request, Team $team): void
    {
        abort_unless($team->id === $request->user()->current_team_id, 403);
    }
}
