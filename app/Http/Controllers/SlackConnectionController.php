<?php

namespace App\Http\Controllers;

use App\Enums\AgentStatus;
use App\Exceptions\SlackApiException;
use App\Http\Requests\Settings\StoreSlackAppTokenRequest;
use App\Http\Requests\Settings\StoreSlackConnectionRequest;
use App\Http\Requests\Settings\UpdateSlackSettingsRequest;
use App\Jobs\UpdateAgentOnServerJob;
use App\Models\Agent;
use App\Models\AgentSlackConnection;
use App\Services\SlackApiService;
use App\Services\SlackAppCleanupService;
use App\Services\SlackManifestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class SlackConnectionController extends Controller
{
    public function create(Request $request, Agent $agent, SlackManifestService $manifestService): Response
    {
        $team = $request->user()->currentTeam;

        abort_unless($agent->team_id === $team->id, 404);
        abort_unless($request->user()->isTeamAdmin($team), 403);

        $connection = $agent->slackConnection;
        $configToken = $team->slackConfigurationToken;

        $step = $this->detectStep($connection, $configToken);

        return Inertia::render('agents/slack-setup', [
            'agent' => $agent->load('slackConnection'),
            'step' => $step,
            'hasConfigToken' => $configToken !== null,
            'manifestYaml' => json_encode($manifestService->generateManifest($agent), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        ]);
    }

    public function initiateApp(Request $request, Agent $agent, SlackApiService $slackApi, SlackManifestService $manifestService): \Symfony\Component\HttpFoundation\Response
    {
        $team = $request->user()->currentTeam;

        abort_unless($agent->team_id === $team->id, 404);
        abort_unless($request->user()->isTeamAdmin($team), 403);

        $configToken = $team->slackConfigurationToken;

        if (! $configToken) {
            return back()->withErrors(['config_token' => 'Please configure a Slack Configuration Token first.']);
        }

        try {
            $token = $slackApi->getValidConfigToken($configToken);
            $manifest = $manifestService->generateManifest($agent);
            $result = $slackApi->createApp($token, $manifest);
        } catch (SlackApiException $e) {
            return back()->withErrors(['slack' => 'Failed to create Slack app: '.$e->slackError]);
        }

        $oauthState = Str::random(40);

        $agent->slackConnection()->updateOrCreate(
            ['agent_id' => $agent->id],
            [
                'slack_app_id' => $result['app_id'],
                'client_id' => $result['credentials']['client_id'],
                'client_secret' => $result['credentials']['client_secret'],
                'signing_secret' => $result['credentials']['signing_secret'],
                'oauth_state' => $oauthState,
                'is_automated' => true,
                'status' => 'disconnected',
            ],
        );

        $redirectUri = route('slack.oauth.callback');
        $oauthUrl = $result['oauth_authorize_url']
            .'&redirect_uri='.urlencode($redirectUri)
            .'&state='.$oauthState;

        return Inertia::location($oauthUrl);
    }

    public function oauthCallback(Request $request, SlackApiService $slackApi): RedirectResponse
    {
        if ($request->has('error')) {
            return to_route('agents.index')
                ->withErrors(['slack' => 'Slack authorization was denied: '.$request->input('error')]);
        }

        $state = $request->input('state');
        $code = $request->input('code');

        $connection = AgentSlackConnection::query()
            ->where('oauth_state', $state)
            ->where('is_automated', true)
            ->first();

        if (! $connection) {
            abort(404);
        }

        try {
            $result = $slackApi->exchangeOAuthCode(
                $connection->client_id,
                $connection->client_secret,
                $code,
                route('slack.oauth.callback'),
            );
        } catch (SlackApiException $e) {
            return to_route('agents.slack.create', $connection->agent_id)
                ->withErrors(['slack' => 'OAuth token exchange failed: '.$e->slackError]);
        }

        $connection->update([
            'bot_token' => $result['bot_token'],
            'slack_team_id' => $result['team_id'],
            'slack_bot_user_id' => $result['bot_user_id'],
            'oauth_state' => null,
        ]);

        $this->syncAvatarToSlack($connection->agent, $result['bot_token'], $slackApi);

        return to_route('agents.slack.create', $connection->agent_id);
    }

    public function store(StoreSlackAppTokenRequest $request, Agent $agent): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        abort_unless($agent->team_id === $team->id, 404);
        abort_unless($request->user()->isTeamAdmin($team), 403);

        $connection = $agent->slackConnection;

        abort_unless($connection && $connection->is_automated && $connection->bot_token, 422);

        $connection->update([
            'app_token' => $request->validated('app_token'),
        ]);

        return to_route('agents.slack.create', $agent);
    }

    public function storePreferences(UpdateSlackSettingsRequest $request, Agent $agent): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        abort_unless($agent->team_id === $team->id, 404);
        abort_unless($request->user()->isTeamAdmin($team), 403);

        $connection = $agent->slackConnection;

        abort_unless($connection && $connection->bot_token && $connection->app_token, 422);

        $connection->update(array_merge($request->validated(), [
            'status' => 'connected',
        ]));

        if ($agent->status === AgentStatus::Pending) {
            return to_route('agents.provisioning', $agent);
        }

        if ($agent->server_id) {
            $agent->update(['is_syncing' => true]);
            UpdateAgentOnServerJob::dispatch($agent);
        }

        return to_route('agents.show', $agent);
    }

    public function storeLegacy(StoreSlackConnectionRequest $request, Agent $agent): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        abort_unless($agent->team_id === $team->id, 404);
        abort_unless($request->user()->isTeamAdmin($team), 403);

        $agent->slackConnection()->updateOrCreate(
            ['agent_id' => $agent->id],
            [
                'bot_token' => $request->validated('bot_token'),
                'app_token' => $request->validated('app_token'),
                'allowed_channels' => $request->validated('allowed_channels'),
                'status' => 'disconnected',
            ],
        );

        return to_route('agents.slack.create', $agent);
    }

    public function updateSettings(UpdateSlackSettingsRequest $request, Agent $agent): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        abort_unless($agent->team_id === $team->id, 404);
        abort_unless($request->user()->isTeamAdmin($team), 403);

        $connection = $agent->slackConnection;

        abort_unless($connection && $connection->status->value === 'connected', 422);

        $connection->update($request->validated());

        if ($agent->server_id) {
            $agent->update(['is_syncing' => true]);
            UpdateAgentOnServerJob::dispatch($agent);
        }

        return back();
    }

    public function destroy(Request $request, Agent $agent, SlackAppCleanupService $cleanupService): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        abort_unless($agent->team_id === $team->id, 404);
        abort_unless($request->user()->isTeamAdmin($team), 403);

        $cleanupService->cleanup($agent);

        $agent->slackConnection?->delete();

        if ($agent->server_id) {
            $agent->update(['is_syncing' => true]);
            UpdateAgentOnServerJob::dispatch($agent);
        }

        return back();
    }

    private function syncAvatarToSlack(Agent $agent, string $botToken, SlackApiService $slackApi): void
    {
        if (! $agent->avatar_path) {
            return;
        }

        try {
            $imageContents = Storage::disk('public')->get($agent->avatar_path);

            if ($imageContents) {
                $slackApi->setBotPhoto($botToken, $imageContents);
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to sync existing avatar to Slack', [
                'agent_id' => $agent->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function detectStep(?AgentSlackConnection $connection, mixed $configToken): string
    {
        if (! $configToken) {
            return 'no-config';
        }

        if (! $connection) {
            return 'create-app';
        }

        if ($connection->slack_app_id && ! $connection->bot_token) {
            return 'oauth-pending';
        }

        if ($connection->bot_token && ! $connection->app_token) {
            return 'enter-xapp';
        }

        if ($connection->bot_token && $connection->app_token && $connection->status->value !== 'connected') {
            return 'configure-preferences';
        }

        if ($connection->bot_token && $connection->app_token && $connection->status->value === 'connected') {
            return 'connected';
        }

        return 'create-app';
    }
}
