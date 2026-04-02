<?php

namespace App\Http\Controllers;

use App\Enums\AgentStatus;
use App\Http\Requests\Settings\StoreTelegramConnectionRequest;
use App\Jobs\UpdateAgentOnServerJob;
use App\Models\Agent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TelegramConnectionController extends Controller
{
    public function create(Request $request, Agent $agent): Response
    {
        $team = $request->user()->currentTeam;

        abort_unless($agent->team_id === $team->id, 404);
        abort_unless($request->user()->isTeamAdmin($team), 403);

        return Inertia::render('agents/telegram-setup', [
            'agent' => $agent->load('telegramConnection'),
        ]);
    }

    public function store(StoreTelegramConnectionRequest $request, Agent $agent): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        abort_unless($agent->team_id === $team->id, 404);
        abort_unless($request->user()->isTeamAdmin($team), 403);

        $agent->telegramConnection()->updateOrCreate(
            ['agent_id' => $agent->id],
            [
                'bot_token' => $request->validated('bot_token'),
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

        $agent->telegramConnection?->delete();

        if ($agent->server_id) {
            $agent->update(['is_syncing' => true]);
            UpdateAgentOnServerJob::dispatch($agent);
        }

        return back();
    }
}
