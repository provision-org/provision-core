<?php

namespace App\Http\Controllers;

use App\Enums\AgentStatus;
use App\Http\Requests\Settings\StoreTelegramConnectionRequest;
use App\Jobs\UpdateAgentOnServerJob;
use App\Models\Agent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class TelegramConnectionController extends Controller
{
    /**
     * Resolve the bot's @username from its token by calling Telegram's
     * getMe endpoint. Returns null on any failure (network, invalid token,
     * Telegram outage) so the connect flow doesn't break when the lookup
     * fails — the row still gets created, just without the display name.
     *
     * Fixes the username-null half of issue #31.
     */
    private function fetchBotUsername(string $botToken): ?string
    {
        try {
            $response = Http::timeout(10)->get("https://api.telegram.org/bot{$botToken}/getMe");
            if (! $response->ok()) {
                return null;
            }
            $payload = $response->json();

            return $payload['ok'] === true ? ($payload['result']['username'] ?? null) : null;
        } catch (\Throwable $e) {
            Log::warning('Telegram getMe failed during connection setup', ['error' => $e->getMessage()]);

            return null;
        }
    }

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

        $botToken = $request->validated('bot_token');

        $agent->telegramConnection()->updateOrCreate(
            ['agent_id' => $agent->id],
            [
                'bot_token' => $botToken,
                'bot_username' => $this->fetchBotUsername($botToken),
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
