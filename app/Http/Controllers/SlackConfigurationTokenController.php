<?php

namespace App\Http\Controllers;

use App\Http\Requests\Settings\StoreSlackConfigTokenRequest;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SlackConfigurationTokenController extends Controller
{
    public function create(Request $request, Team $team): Response
    {
        abort_unless($request->user()->isTeamAdmin($team), 403);

        return Inertia::render('settings/teams/slack-config', [
            'team' => $team,
            'configToken' => $team->slackConfigurationToken,
        ]);
    }

    public function store(StoreSlackConfigTokenRequest $request, Team $team): RedirectResponse
    {
        abort_unless($request->user()->isTeamAdmin($team), 403);

        $team->slackConfigurationToken()->updateOrCreate(
            ['team_id' => $team->id],
            [
                'access_token' => $request->validated('access_token'),
                'refresh_token' => $request->validated('refresh_token'),
                'expires_at' => now()->addHour(),
            ],
        );

        return back();
    }

    public function destroy(Request $request, Team $team): RedirectResponse
    {
        abort_unless($request->user()->isTeamAdmin($team), 403);

        $team->slackConfigurationToken?->delete();

        return back();
    }
}
