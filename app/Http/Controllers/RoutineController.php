<?php

namespace App\Http\Controllers;

use App\Models\Routine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RoutineController extends Controller
{
    public function index(Request $request): Response
    {
        $team = $request->user()->currentTeam;

        $routines = $team->routines()
            ->with('agent:id,name,emoji')
            ->orderByDesc('created_at')
            ->get();

        return Inertia::render('company/routines/index', [
            'routines' => $routines,
            'agents' => $team->agents()->select(['id', 'name', 'emoji'])->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'agent_id' => ['required', 'string', 'exists:agents,id'],
            'cron_expression' => ['required', 'string', 'max:255'],
            'timezone' => ['nullable', 'string', 'timezone:all'],
        ]);

        // Verify agent belongs to team
        abort_unless(
            $team->agents()->where('id', $validated['agent_id'])->exists(),
            422,
            'Agent does not belong to this team.',
        );

        // Enforce per-team limit
        $activeCount = $team->routines()->where('status', 'active')->count();
        abort_if($activeCount >= 50, 422, 'Maximum of 50 active routines per team.');

        $routine = $team->routines()->create([
            ...$validated,
            'timezone' => $validated['timezone'] ?? 'UTC',
            'status' => 'active',
        ]);

        $routine->update([
            'next_run_at' => $routine->computeNextRun(),
        ]);

        return redirect()->route('company.routines.index')
            ->with('success', 'Routine created.');
    }

    public function update(Request $request, Routine $routine): RedirectResponse
    {
        $this->authorizeTeam($request, $routine);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'agent_id' => ['required', 'string', 'exists:agents,id'],
            'cron_expression' => ['required', 'string', 'max:255'],
            'timezone' => ['nullable', 'string', 'timezone:all'],
        ]);

        $team = $request->user()->currentTeam;

        abort_unless(
            $team->agents()->where('id', $validated['agent_id'])->exists(),
            422,
            'Agent does not belong to this team.',
        );

        $routine->update([
            ...$validated,
            'timezone' => $validated['timezone'] ?? 'UTC',
        ]);

        $routine->update([
            'next_run_at' => $routine->computeNextRun(),
        ]);

        return redirect()->route('company.routines.index')
            ->with('success', 'Routine updated.');
    }

    public function toggle(Request $request, Routine $routine): RedirectResponse
    {
        $this->authorizeTeam($request, $routine);

        $newStatus = $routine->status === 'active' ? 'paused' : 'active';

        $routine->update([
            'status' => $newStatus,
            'next_run_at' => $newStatus === 'active' ? $routine->computeNextRun() : null,
        ]);

        return redirect()->route('company.routines.index')
            ->with('success', 'Routine '.($newStatus === 'active' ? 'activated' : 'paused').'.');
    }

    public function destroy(Request $request, Routine $routine): RedirectResponse
    {
        $this->authorizeTeam($request, $routine);

        $routine->delete();

        return redirect()->route('company.routines.index')
            ->with('success', 'Routine deleted.');
    }

    private function authorizeTeam(Request $request, Routine $routine): void
    {
        abort_unless($routine->team_id === $request->user()->current_team_id, 403);
    }
}
