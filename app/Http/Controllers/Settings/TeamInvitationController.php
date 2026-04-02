<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\InviteTeamMemberRequest;
use App\Mail\TeamInvitationMail;
use App\Models\Team;
use App\Models\TeamInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class TeamInvitationController extends Controller
{
    /**
     * Invite a new team member.
     */
    public function store(InviteTeamMemberRequest $request, Team $team): RedirectResponse
    {
        abort_unless($team->hasUser($request->user()), 403);

        if ($team->members()->where('email', $request->email)->exists()) {
            throw ValidationException::withMessages([
                'email' => ['This user is already a team member.'],
            ]);
        }

        if ($team->invitations()->where('email', $request->email)->exists()) {
            throw ValidationException::withMessages([
                'email' => ['This user has already been invited.'],
            ]);
        }

        $invitation = $team->invitations()->create([
            'email' => $request->email,
            'role' => $request->role,
        ]);

        Mail::to($request->email)->send(new TeamInvitationMail($invitation));

        return back();
    }

    /**
     * Accept a team invitation.
     */
    public function accept(Request $request, TeamInvitation $invitation): RedirectResponse
    {
        abort_unless($request->user()->email === $invitation->email, 403);

        $team = $invitation->team;

        $team->members()->attach($request->user(), [
            'role' => $invitation->role->value,
        ]);

        $request->user()->switchTeam($team);

        $invitation->delete();

        return to_route('teams.show', $team);
    }

    /**
     * Cancel a team invitation.
     */
    public function destroy(Request $request, TeamInvitation $invitation): RedirectResponse
    {
        $team = $invitation->team;

        $isAdmin = $request->user()->isTeamAdmin($team);
        $isInvitee = $request->user()->email === $invitation->email;

        abort_unless($isAdmin || $isInvitee, 403);

        $invitation->delete();

        return back();
    }
}
