<?php

namespace App\Http\Controllers\Settings;

use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TeamMemberController extends Controller
{
    /**
     * Update a team member's role.
     */
    public function update(Request $request, Team $team, User $user): RedirectResponse
    {
        abort_unless($request->user()->isTeamAdmin($team), 403);
        abort_if($user->isTeamOwner($team), 403);

        $request->validate([
            'role' => ['required', 'string', Rule::enum(TeamRole::class)],
        ]);

        $team->members()->updateExistingPivot($user->id, [
            'role' => $request->role,
        ]);

        return back();
    }

    /**
     * Remove a team member.
     */
    public function destroy(Request $request, Team $team, User $user): RedirectResponse
    {
        abort_if($user->isTeamOwner($team), 403);

        $isAdmin = $request->user()->isTeamAdmin($team);
        $isSelf = $request->user()->id === $user->id;

        abort_unless($isAdmin || $isSelf, 403);

        $team->members()->detach($user);

        if ($user->current_team_id === $team->id) {
            $user->switchTeam($user->personalTeam());
        }

        if ($isSelf) {
            return to_route('teams.show', $request->user()->fresh()->currentTeam);
        }

        return back();
    }
}
