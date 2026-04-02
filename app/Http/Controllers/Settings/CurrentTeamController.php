<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CurrentTeamController extends Controller
{
    /**
     * Switch the user's current team.
     */
    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'team_id' => ['required', 'exists:teams,id'],
        ]);

        $team = Team::findOrFail($request->team_id);

        abort_unless($team->hasUser($request->user()), 403);

        $request->user()->switchTeam($team);

        return redirect('/dashboard');
    }
}
