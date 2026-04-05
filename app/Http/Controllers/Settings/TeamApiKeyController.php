<?php

namespace App\Http\Controllers\Settings;

use App\Enums\ServerStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreApiKeyRequest;
use App\Http\Requests\Settings\UpdateApiKeyRequest;
use App\Jobs\UpdateEnvOnServerJob;
use App\Models\Team;
use App\Models\TeamApiKey;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TeamApiKeyController extends Controller
{
    /**
     * List API keys with masked values.
     */
    public function index(Request $request, Team $team): Response
    {
        abort_unless($request->user()->isTeamAdmin($team), 403);

        return Inertia::render('settings/teams/api-keys/index', [
            'team' => $team,
            'apiKeys' => $team->apiKeys->map(fn (TeamApiKey $key) => [
                'id' => $key->id,
                'provider' => $key->provider,
                'masked_key' => $key->maskedKey(),
                'is_active' => $key->is_active,
                'created_at' => $key->created_at,
                'updated_at' => $key->updated_at,
            ]),
        ]);
    }

    /**
     * Create or upsert an API key.
     */
    public function store(StoreApiKeyRequest $request, Team $team): RedirectResponse
    {
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
    public function update(UpdateApiKeyRequest $request, Team $team, TeamApiKey $apiKey): RedirectResponse
    {
        abort_unless($request->user()->isTeamAdmin($team), 403);

        $apiKey->update(array_filter($request->validated(), fn ($value) => $value !== null));

        $this->dispatchEnvUpdate($team);

        return back();
    }

    /**
     * Delete an API key.
     */
    public function destroy(Request $request, Team $team, TeamApiKey $apiKey): RedirectResponse
    {
        abort_unless($request->user()->isTeamAdmin($team), 403);

        $apiKey->delete();

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
