<?php

namespace App\Http\Controllers;

use App\Enums\ActorType;
use App\Http\Requests\Governance\UpdateReportingRequest;
use App\Models\Agent;
use App\Models\Team;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OrgChartController extends Controller
{
    public function __construct(
        private readonly AuditService $audit,
    ) {}

    public function index(Request $request): Response
    {
        $team = $request->user()->currentTeam;

        $agents = $team->agents()
            ->select([
                'id', 'name', 'role', 'agent_mode', 'status',
                'reports_to', 'org_title', 'capabilities', 'delegation_enabled',
                'avatar_path',
            ])
            ->with(['manager:id,name', 'directReports:id,name,reports_to'])
            ->get();

        return Inertia::render('company/org/index', [
            'agents' => $agents,
            'team' => $team,
        ]);
    }

    public function updateReporting(UpdateReportingRequest $request, Agent $agent): RedirectResponse
    {
        $this->authorizeTeam($request, $agent->team);

        $validated = $request->validated();

        // Validate no cycle in org hierarchy
        if (array_key_exists('reports_to', $validated)) {
            abort_unless(
                $agent->validateOrgHierarchy($validated['reports_to']),
                422,
                'This reporting change would create a cycle in the org chart.',
            );
        }

        $agent->update($validated);

        $this->audit->log(
            teamId: $agent->team_id,
            actorType: ActorType::User,
            actorId: $request->user()->id,
            action: 'agent.reporting_updated',
            targetType: 'agent',
            targetId: $agent->id,
            payload: $validated,
        );

        return redirect()->route('company.org.index')
            ->with('success', 'Reporting structure updated.');
    }

    private function authorizeTeam(Request $request, Team $team): void
    {
        abort_unless($team->id === $request->user()->current_team_id, 403);
    }
}
