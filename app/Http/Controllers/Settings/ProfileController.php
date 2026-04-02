<?php

namespace App\Http\Controllers\Settings;

use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Models\User;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return to_route('profile.edit');
    }

    /**
     * Delete the user's profile.
     */
    public function destroy(ProfileDeleteRequest $request): RedirectResponse
    {
        $user = $request->user();

        $this->cleanupTeams($user);

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }

    /**
     * Clean up teams when deleting a user account.
     */
    private function cleanupTeams(User $user): void
    {
        // Delete all personal teams (cascade deletes pivot + invitations)
        $user->ownedTeams()->where('personal_team', true)->delete();

        // For non-personal owned teams, transfer ownership or delete
        foreach ($user->ownedTeams()->where('personal_team', false)->get() as $team) {
            $nextAdmin = $team->members()
                ->wherePivot('role', TeamRole::Admin->value)
                ->where('users.id', '!=', $user->id)
                ->first();

            if ($nextAdmin) {
                $team->forceFill(['user_id' => $nextAdmin->id])->save();
                $team->members()->detach($user);
            } else {
                $team->delete();
            }
        }

        // Detach from any teams where user is just a member
        $user->teams()->detach();
    }
}
