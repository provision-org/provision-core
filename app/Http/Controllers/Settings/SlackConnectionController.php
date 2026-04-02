<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreSlackConnectionRequest;
use App\Jobs\UpdateAgentOnServerJob;
use App\Models\Agent;
use App\Models\Team;
use App\Services\SlackManifestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SlackConnectionController extends Controller
{
    public function create(Request $request, Team $team, Agent $agent, SlackManifestService $manifestService): Response
    {
        abort_unless($request->user()->isTeamAdmin($team), 403);

        return Inertia::render('settings/agents/slack-setup', [
            'team' => $team,
            'agent' => $agent,
            'manifestYaml' => json_encode($manifestService->generateManifest($agent), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        ]);
    }

    public function store(StoreSlackConnectionRequest $request, Team $team, Agent $agent): RedirectResponse
    {
        abort_unless($request->user()->isTeamAdmin($team), 403);

        $agent->slackConnection()->updateOrCreate(
            ['agent_id' => $agent->id],
            [
                'bot_token' => $request->validated('bot_token'),
                'app_token' => $request->validated('app_token'),
                'allowed_channels' => $request->validated('allowed_channels'),
                'status' => 'connected',
            ],
        );

        if ($agent->server_id) {
            UpdateAgentOnServerJob::dispatch($agent);
        }

        return to_route('agents.show', [$team, $agent]);
    }

    public function destroy(Request $request, Team $team, Agent $agent): RedirectResponse
    {
        abort_unless($request->user()->isTeamAdmin($team), 403);

        $agent->slackConnection?->delete();

        if ($agent->server_id) {
            UpdateAgentOnServerJob::dispatch($agent);
        }

        return back();
    }
}
