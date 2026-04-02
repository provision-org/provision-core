<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreEnvVarRequest;
use App\Http\Requests\Settings\UpdateEnvVarRequest;
use App\Jobs\UpdateEnvOnServerJob;
use App\Models\Team;
use App\Models\TeamEnvVar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TeamEnvVarController extends Controller
{
    /**
     * List environment variables (secrets masked).
     */
    public function index(Request $request, Team $team): Response
    {
        abort_unless($request->user()->isTeamAdmin($team), 403);

        return Inertia::render('settings/teams/env-vars/index', [
            'team' => $team,
            'envVars' => $team->envVars->map(fn (TeamEnvVar $var) => [
                'id' => $var->id,
                'key' => $var->key,
                'value_preview' => $var->valuePreview(),
                'is_secret' => $var->is_secret,
                'created_at' => $var->created_at,
                'updated_at' => $var->updated_at,
            ]),
        ]);
    }

    /**
     * Create an environment variable.
     */
    public function store(StoreEnvVarRequest $request, Team $team): RedirectResponse
    {
        abort_unless($request->user()->isTeamAdmin($team), 403);

        $team->envVars()->create($request->validated());

        $this->dispatchEnvUpdate($team);

        return back();
    }

    /**
     * Update an environment variable.
     */
    public function update(UpdateEnvVarRequest $request, Team $team, TeamEnvVar $envVar): RedirectResponse
    {
        abort_unless($request->user()->isTeamAdmin($team), 403);

        $envVar->update($request->validated());

        $this->dispatchEnvUpdate($team);

        return back();
    }

    /**
     * Delete an environment variable.
     */
    public function destroy(Request $request, Team $team, TeamEnvVar $envVar): RedirectResponse
    {
        abort_unless($request->user()->isTeamAdmin($team), 403);

        $envVar->delete();

        $this->dispatchEnvUpdate($team);

        return back();
    }

    private function dispatchEnvUpdate(Team $team): void
    {
        if ($team->server?->status === \App\Enums\ServerStatus::Running) {
            UpdateEnvOnServerJob::dispatch($team->server);
        }
    }
}
