<?php

namespace App\Http\Controllers;

use App\Enums\ServerStatus;
use App\Http\Requests\Settings\StoreApiKeyRequest;
use App\Http\Requests\Settings\StoreEnvVarRequest;
use App\Http\Requests\Settings\UpdateApiKeyRequest;
use App\Http\Requests\Settings\UpdateEnvVarRequest;
use App\Jobs\UpdateEnvOnServerJob;
use App\Models\Team;
use App\Models\TeamApiKey;
use App\Models\TeamEnvVar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ApiKeyController extends Controller
{
    /**
     * List API keys with masked values.
     */
    public function index(Request $request): Response
    {
        $team = $request->user()->currentTeam;

        abort_unless($request->user()->isTeamAdmin($team), 403);

        return Inertia::render('api-keys/index', [
            'team' => $team,
            'apiKeys' => $team->apiKeys->map(fn (TeamApiKey $key) => [
                'id' => $key->id,
                'provider' => $key->provider,
                'masked_key' => $key->maskedKey(),
                'is_active' => $key->is_active,
                'created_at' => $key->created_at,
                'updated_at' => $key->updated_at,
            ]),
            'envVars' => $team->envVars->map(fn (TeamEnvVar $var) => [
                'id' => $var->id,
                'key' => $var->key,
                'value_preview' => $var->valuePreview(),
                'is_secret' => $var->is_secret,
                'is_system' => $var->is_system,
                'created_at' => $var->created_at,
                'updated_at' => $var->updated_at,
            ]),
        ]);
    }

    /**
     * Create or upsert an API key.
     */
    public function store(StoreApiKeyRequest $request): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        abort_unless($request->user()->isTeamAdmin($team), 403);

        $team->apiKeys()->updateOrCreate(
            ['provider' => $request->provider],
            ['api_key' => $request->api_key],
        );

        $this->dispatchEnvUpdate($team);

        return back();
    }

    /**
     * Update an API key.
     */
    public function update(UpdateApiKeyRequest $request, TeamApiKey $apiKey): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        abort_unless($request->user()->isTeamAdmin($team), 403);

        $apiKey->update(array_filter($request->validated(), fn ($value) => $value !== null));

        $this->dispatchEnvUpdate($team);

        return back();
    }

    /**
     * Delete an API key.
     */
    public function destroy(Request $request, TeamApiKey $apiKey): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        abort_unless($request->user()->isTeamAdmin($team), 403);

        $apiKey->delete();

        $this->dispatchEnvUpdate($team);

        return back();
    }

    /**
     * Create an environment variable.
     */
    public function storeEnvVar(StoreEnvVarRequest $request): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        abort_unless($request->user()->isTeamAdmin($team), 403);

        $existingSystemVar = $team->envVars()
            ->where('key', $request->validated('key'))
            ->where('is_system', true)
            ->exists();

        abort_if($existingSystemVar, 403);

        $team->envVars()->create($request->validated());

        $this->dispatchEnvUpdate($team);

        return back();
    }

    /**
     * Update an environment variable.
     */
    public function updateEnvVar(UpdateEnvVarRequest $request, TeamEnvVar $envVar): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        abort_unless($request->user()->isTeamAdmin($team), 403);
        abort_if($envVar->is_system, 403);

        $envVar->update($request->validated());

        $this->dispatchEnvUpdate($team);

        return back();
    }

    /**
     * Delete an environment variable.
     */
    public function destroyEnvVar(Request $request, TeamEnvVar $envVar): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        abort_unless($request->user()->isTeamAdmin($team), 403);
        abort_if($envVar->is_system, 403);

        $envVar->delete();

        $this->dispatchEnvUpdate($team);

        return back();
    }

    private function dispatchEnvUpdate(Team $team): void
    {
        if ($team->server?->status === ServerStatus::Running) {
            UpdateEnvOnServerJob::dispatch($team->server);
        }
    }
}
