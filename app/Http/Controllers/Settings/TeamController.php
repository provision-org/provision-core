<?php

namespace App\Http\Controllers\Settings;

use App\Ai\CompanyExtractorAgent;
use App\Contracts\Modules\BillingProvider;
use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\CreateTeamRequest;
use App\Http\Requests\Settings\UpdateTeamRequest;
use App\Jobs\DestroyTeamJob;
use App\Mail\TeamCreatedMail;
use App\Models\ServerEvent;
use App\Models\Team;
use App\Services\FirecrawlService;
use App\Services\ServerProvisioningDispatcher;
use App\Support\Provision;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;

class TeamController extends Controller
{
    /**
     * Show the team creation page.
     */
    public function create(): Response
    {
        $selectionEnabled = config('cloud.provider_selection_enabled');

        $availableProviders = [];
        if ($selectionEnabled) {
            $availableProviders[] = ['value' => 'docker', 'label' => 'Docker', 'description' => 'Run locally on this machine. No cloud account needed.'];

            if (config('cloud.digitalocean.api_token')) {
                $availableProviders[] = ['value' => 'digitalocean', 'label' => 'DigitalOcean', 'description' => 'Deploy to DigitalOcean droplets.'];
            }

            if (config('cloud.hetzner.api_token')) {
                $availableProviders[] = ['value' => 'hetzner', 'label' => 'Hetzner', 'description' => 'Deploy to Hetzner Cloud servers.'];
            }

            if (config('cloud.linode.api_token')) {
                $availableProviders[] = ['value' => 'linode', 'label' => 'Linode', 'description' => 'Deploy to Linode instances.'];
            }
        }

        return Inertia::render('settings/teams/create', [
            'harnessSelectionEnabled' => (bool) config('provision.enable_multiple_harness', false),
            'cloudProviderSelectionEnabled' => $selectionEnabled && count($availableProviders) > 1,
            'availableProviders' => $availableProviders,
            'defaultProvider' => config('cloud.default_provider', 'docker'),
        ]);
    }

    /**
     * Create a new team.
     */
    public function store(CreateTeamRequest $request): RedirectResponse
    {
        $team = $request->user()->ownedTeams()->create([
            'name' => $request->name,
            'personal_team' => false,
            'timezone' => $request->user()->timezone ?? 'UTC',
            'cloud_provider' => $request->cloud_provider ?? config('cloud.default_provider', 'docker'),
            'harness_type' => $request->harness_type ?? 'openclaw',
            'company_name' => $request->company_name,
            'company_url' => $request->company_url,
            'company_description' => $request->company_description,
            'target_market' => $request->target_market,
        ]);

        $team->members()->attach($request->user(), ['role' => TeamRole::Admin->value]);

        $request->user()->switchTeam($team);

        $billingModel = Provision::teamModel();
        if ($billingModel !== Team::class) {
            $billingTeam = $billingModel::find($team->id);
            if ($billingTeam && method_exists($billingTeam, 'creditWallet')) {
                $billingTeam->creditWallet()->create([
                    'balance_cents' => 0,
                    'lifetime_credits_cents' => 0,
                    'lifetime_usage_cents' => 0,
                ]);
            }
        }

        Mail::to($request->user()->email)->send(new TeamCreatedMail($team));

        // If billing is active, redirect to subscribe before provisioning
        if (app()->bound(BillingProvider::class)) {
            return to_route('subscribe');
        }

        $this->provisionServer($team);

        return to_route('teams.provisioning', $team);
    }

    /**
     * Show the server provisioning page.
     */
    public function provisioning(Request $request, Team $team): Response|RedirectResponse
    {
        abort_unless($team->hasUser($request->user()), 403);

        // Require subscription before provisioning when billing is active
        if (app()->bound(BillingProvider::class)) {
            $billingModel = Provision::teamModel();
            $billingTeam = $billingModel::find($team->id);
            if ($billingTeam && ! $billingTeam->subscribed('default')) {
                return to_route('subscribe');
            }
        }

        $server = $team->server;

        if ($server?->status === ServerStatus::Running) {
            return to_route('agents.index');
        }

        return Inertia::render('settings/teams/provisioning', [
            'team' => $team->only('id', 'name'),
            'server' => $server ? [
                ...$server->only('id', 'status'),
                'events' => $server->events()
                    ->whereIn('event', ['cloud_init_progress', 'setup_progress', 'server_ready', 'setup_complete', 'provisioning_started'])
                    ->orderBy('created_at', 'asc')
                    ->get()
                    ->map(fn (ServerEvent $e) => [
                        'id' => $e->id,
                        'event' => $e->event,
                        'step' => $e->payload['step'] ?? null,
                        'created_at' => $e->created_at->toISOString(),
                    ]),
            ] : null,
        ]);
    }

    /**
     * Scrape a company website and extract structured data.
     */
    public function scrapeCompany(Request $request, FirecrawlService $firecrawl, CompanyExtractorAgent $extractor): JsonResponse
    {
        $request->validate([
            'url' => ['required', 'url', 'max:500'],
        ]);

        try {
            $scraped = $firecrawl->scrape($request->input('url'));
            $extracted = $extractor->extract($scraped['markdown']);

            return response()->json($extracted);
        } catch (\Throwable $e) {
            Log::warning('Company scrape failed', [
                'url' => $request->input('url'),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to analyze website. Please fill in the fields manually.',
            ], 422);
        }
    }

    /**
     * Show a team's settings page.
     */
    public function show(Request $request, Team $team): Response|RedirectResponse
    {
        abort_unless($team->hasUser($request->user()), 403);

        $server = $team->server;
        if ($server && $server->status !== ServerStatus::Running) {
            return to_route('teams.provisioning', $team);
        }

        return Inertia::render('settings/teams/show', [
            'team' => $team->load('owner'),
            'members' => $team->members()->get(),
            'invitations' => $team->invitations()->get(),
            'isAdmin' => $request->user()->isTeamAdmin($team),
            'isOwner' => $request->user()->isTeamOwner($team),
        ]);
    }

    /**
     * Update a team's name.
     */
    public function update(UpdateTeamRequest $request, Team $team): RedirectResponse
    {
        abort_unless($request->user()->isTeamAdmin($team), 403);

        $team->update($request->validated());

        return back();
    }

    private function provisionServer(Team $team): void
    {
        if ($team->server) {
            return;
        }

        $server = $team->server()->create([
            'name' => "provision-{$team->id}",
            'cloud_provider' => $team->cloudProvider(),
        ]);

        ServerProvisioningDispatcher::dispatch($server);
    }

    /**
     * Delete a team.
     */
    public function destroy(Request $request, Team $team): RedirectResponse
    {
        abort_unless($request->user()->isTeamOwner($team), 403);
        abort_if($team->personal_team, 403);

        DestroyTeamJob::dispatch($team);

        $user = $request->user();
        $nextTeam = $user->personalTeam()
            ?? $user->teams()->where('teams.id', '!=', $team->id)->first();

        if ($nextTeam) {
            $user->switchTeam($nextTeam);

            return to_route('agents.index');
        }

        $user->update(['current_team_id' => null]);

        return to_route('teams.create');
    }
}
