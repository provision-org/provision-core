<?php

namespace App\Http\Controllers;

use App\Enums\AgentStatus;
use App\Http\Requests\Settings\StoreDiscordConnectionRequest;
use App\Jobs\UpdateAgentOnServerJob;
use App\Models\Agent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DiscordConnectionController extends Controller
{
    public function create(Request $request, Agent $agent): Response
    {
        $team = $request->user()->currentTeam;

        abort_unless($agent->team_id === $team->id, 404);
        abort_unless($request->user()->isTeamAdmin($team), 403);

        return Inertia::render('agents/discord-setup', [
            'agent' => $agent->load('discordConnection'),
        ]);
    }

    public function store(StoreDiscordConnectionRequest $request, Agent $agent): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        abort_unless($agent->team_id === $team->id, 404);
        abort_unless($request->user()->isTeamAdmin($team), 403);

        $agent->discordConnection()->updateOrCreate(
            ['agent_id' => $agent->id],
            [
                'token' => $request->validated('token'),
                'guild_id' => $request->validated('guild_id'),
                'require_mention' => $request->boolean('require_mention', true),
                'status' => 'connected',
            ],
        );

        if ($agent->status === AgentStatus::Pending) {
            return to_route('agents.provisioning', $agent);
        }

        if ($agent->server_id) {
            $agent->update(['is_syncing' => true]);
            UpdateAgentOnServerJob::dispatch($agent);
        }

        return to_route('agents.show', $agent);
    }

    public function destroy(Request $request, Agent $agent): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        abort_unless($agent->team_id === $team->id, 404);
        abort_unless($request->user()->isTeamAdmin($team), 403);

        $agent->discordConnection?->delete();

        if ($agent->server_id) {
            $agent->update(['is_syncing' => true]);
            UpdateAgentOnServerJob::dispatch($agent);
        }

        return back();
    }
}
