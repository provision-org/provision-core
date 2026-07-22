<?php

namespace App\Http\Controllers\Settings;

use App\Ai\CompanyExtractorAgent;
use App\Contracts\Modules\BillingProvider;
use App\Enums\AgentStatus;
use App\Enums\CloudProvider;
use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\CreateTeamRequest;
use App\Http\Requests\Settings\UpdateTeamRequest;
use App\Jobs\DestroyTeamJob;
use App\Mail\TeamCreatedMail;
use App\Models\AgentApiToken;
use App\Models\ServerEvent;
use App\Models\Team;
use App\Services\Aws\AwsCredentials;
use App\Services\Aws\BedrockCatalogService;
use App\Services\Aws\MantleCatalogService;
use App\Services\CloudServiceFactory;
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
use RuntimeException;

class TeamController extends Controller
{
    /**
     * Show the team creation page.
     */
    public function create(Request $request): Response
    {
        $selectionEnabled = config('cloud.provider_selection_enabled');
        $byoCloudEnabled = (bool) $request->user()->byo_cloud_enabled;

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
        } elseif ($byoCloudEnabled) {
            // Global provider selection is off (hosted default), but a BYO-cloud
            // user still chooses per team between the managed default and their
            // own AWS account, so the provider step is forced on.
            $default = CloudProvider::tryFrom(config('cloud.default_provider', 'docker')) ?? CloudProvider::Docker;
            $availableProviders[] = ['value' => $default->value, 'label' => $default->label(), 'description' => 'Managed by Provision. No cloud account needed.'];
        }

        // BYO AWS uses per-team credentials, so no global-token check —
        // eligibility is the account-level byo_cloud_enabled flag.
        if ($byoCloudEnabled) {
            $availableProviders[] = ['value' => 'aws', 'label' => 'AWS (your account)', 'description' => 'Deploy to EC2 in your own AWS account.'];
        }

        return Inertia::render('settings/teams/create', [
            'harnessSelectionEnabled' => (bool) config('provision.enable_multiple_harness', false),
            'cloudProviderSelectionEnabled' => $byoCloudEnabled || ($selectionEnabled && count($availableProviders) > 1),
            'availableProviders' => $availableProviders,
            'defaultProvider' => config('cloud.default_provider', 'docker'),
            'byoCloudEnabled' => $byoCloudEnabled,
        ]);
    }

    /**
     * Create a new team.
     */
    public function store(CreateTeamRequest $request): RedirectResponse
    {
        $isAwsTeam = $request->cloud_provider === CloudProvider::Aws->value;

        // The server is the source of truth on BYO-AWS credentials: verify
        // them against AWS before any team/server/api-key row exists, even
        // though the wizard already ran the same check client-side.
        if ($isAwsTeam) {
            try {
                app(CloudServiceFactory::class)
                    ->makeAwsForCredentials($this->awsCredentialsFromRequest($request))
                    ->verifyCredentials();
            } catch (RuntimeException $e) {
                return back()->withErrors([
                    'aws_key_id' => "We could not verify these AWS credentials: {$e->getMessage()}",
                ]);
            }
        }

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

        // Store BYO-AWS credentials as an encrypted JSON cloud key before
        // any provisioning path can run. Never logged.
        if ($team->cloudProvider() === CloudProvider::Aws) {
            $credentials = [
                'key_id' => $request->aws_key_id,
                'secret' => $request->aws_secret,
                'region' => $request->aws_region ?? config('cloud.aws.default_region', 'us-east-1'),
            ];

            // Optional EC2 instance profile — enables the Bedrock model tier
            // (agents authenticate via the role, no API keys on the server).
            if ($request->filled('aws_instance_profile')) {
                $credentials['instance_profile'] = $request->aws_instance_profile;
            }

            // Team-wide default Bedrock model the customer picked in the wizard.
            // Stored in internal "mantle:<raw>" (Mantle) or "bedrock:<raw>"
            // (classic) form; seeds each new agent.
            if ($request->filled('aws_bedrock_model')) {
                $credentials['default_bedrock_model'] = $this->normalizeBedrockModelId(
                    (string) $request->aws_bedrock_model,
                    $credentials['region'],
                );
            }

            $team->apiKeys()->create([
                'provider_type' => 'cloud',
                'provider' => CloudProvider::Aws->value,
                'api_key' => json_encode($credentials),
                'is_active' => true,
            ]);
        }

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
     * Verify BYO-AWS credentials against AWS (STS GetCallerIdentity) for the
     * team wizard's "Verify connection" step. Never echoes the secret back.
     */
    public function verifyAws(Request $request, CloudServiceFactory $factory): JsonResponse
    {
        abort_unless((bool) $request->user()->byo_cloud_enabled, 403);

        $request->validate([
            'aws_key_id' => ['required', 'string', 'max:128'],
            'aws_secret' => ['required', 'string', 'max:128'],
            'aws_region' => ['required', 'string', 'max:32'],
        ]);

        try {
            $identity = $factory
                ->makeAwsForCredentials($this->awsCredentialsFromRequest($request))
                ->verifyCredentials();
        } catch (RuntimeException $e) {
            return response()->json([
                'verified' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'verified' => true,
            'account_id' => $identity['account_id'],
        ]);
    }

    /**
     * List the Bedrock models the account can actually invoke, plus an
     * auto-detected "best available" default. Dual-mode: the team-creation
     * wizard posts fresh AWS keys; the agent wizard (existing team) posts none
     * and we read the team's stored cloud creds. Never echoes the secret back.
     */
    public function bedrockModels(Request $request, CloudServiceFactory $factory): JsonResponse
    {
        abort_unless((bool) $request->user()->byo_cloud_enabled, 403);

        try {
            $catalog = $this->bedrockCatalog($request, $factory);
            $models = $catalog->listModels();
            $default = $catalog->resolveBestDefaultModel($models);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $prefix = $catalog instanceof MantleCatalogService ? 'mantle' : 'bedrock';

        return response()->json([
            'models' => $models,
            // 'mantle' exposes richer models (incl. Claude 5) with no use-case
            // form + per-model zeroRetention; 'classic' is the ConverseStream
            // fallback. Drives which prefix the wizard stores model ids under.
            'mode' => $prefix,
            // Default in the internal "<prefix>:<raw>" form the app stores;
            // null when nothing usable was detected.
            'default' => $default ? $prefix.':'.$default : null,
        ]);
    }

    /**
     * Invoke-check a single chosen Bedrock model (ConverseStream) so the wizard
     * can confirm the selection works — or surface the Anthropic use-case-form
     * gate — before the team/agent is saved.
     */
    public function verifyBedrockModel(Request $request, CloudServiceFactory $factory): JsonResponse
    {
        abort_unless((bool) $request->user()->byo_cloud_enabled, 403);

        $request->validate([
            'model_id' => ['required', 'string', 'max:255'],
        ]);

        // Accept the internal "mantle:<raw>" / "bedrock:<raw>" form or a bare id.
        $rawId = (string) preg_replace('/^(bedrock|mantle):/', '', (string) $request->input('model_id'));

        try {
            $result = $this->bedrockCatalog($request, $factory)->verifyModel($rawId);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }

        return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
    }

    /**
     * Normalise a wizard-supplied model id to the internal "<prefix>:<raw>"
     * form. An explicit "mantle:"/"bedrock:" prefix is honoured; a bare id is
     * prefixed by region (Mantle where supported, classic elsewhere).
     */
    private function normalizeBedrockModelId(string $value, string $region): string
    {
        $raw = (string) preg_replace('/^(bedrock|mantle):/', '', $value);

        if (str_starts_with($value, 'mantle:')) {
            return 'mantle:'.$raw;
        }
        if (str_starts_with($value, 'bedrock:')) {
            return 'bedrock:'.$raw;
        }

        $prefix = in_array($region, MantleCatalogService::SUPPORTED_REGIONS, true) ? 'mantle' : 'bedrock';

        return $prefix.':'.$raw;
    }

    /**
     * Pick the catalog backend for the resolved credentials: the Mantle endpoint
     * (bearer token, no use-case form, richer catalog incl. ZDR flags) when the
     * region supports it, else the classic ConverseStream catalog. Mantle is the
     * primary path; classic is the fallback for regions Mantle hasn't reached.
     */
    private function bedrockCatalog(Request $request, CloudServiceFactory $factory): MantleCatalogService|BedrockCatalogService
    {
        $credentials = $this->resolveBedrockCredentials($request);

        return in_array($credentials->region, MantleCatalogService::SUPPORTED_REGIONS, true)
            ? $factory->makeMantleCatalogForCredentials($credentials)
            : $factory->makeBedrockCatalogForCredentials($credentials);
    }

    /**
     * Resolve AWS credentials for a Bedrock catalog call: from the request when
     * the wizard posts fresh keys (team creation), otherwise from the current
     * team's stored cloud key (agent wizard on an existing team).
     */
    private function resolveBedrockCredentials(Request $request): AwsCredentials
    {
        if ($request->filled('aws_key_id')) {
            return $this->awsCredentialsFromRequest($request);
        }

        $team = $request->user()->currentTeam;
        $cloudKey = $team?->cloudApiKeys()
            ->where('provider', CloudProvider::Aws->value)
            ->where('is_active', true)
            ->first();

        if (! $cloudKey) {
            throw new RuntimeException('This team has no connected AWS account.');
        }

        return AwsCredentials::fromJson($cloudKey->api_key);
    }

    /**
     * Build an AwsCredentials value object from the wizard's request input.
     */
    private function awsCredentialsFromRequest(Request $request): AwsCredentials
    {
        return new AwsCredentials(
            keyId: (string) $request->input('aws_key_id'),
            secret: (string) $request->input('aws_secret'),
            region: (string) ($request->input('aws_region') ?: config('cloud.aws.default_region', 'us-east-1')),
            instanceProfile: $request->filled('aws_instance_profile') ? $request->input('aws_instance_profile') : null,
        );
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
                    ->whereIn('event', ['cloud_init_progress', 'setup_progress', 'server_ready', 'setup_complete', 'gateway_restarted', 'provisioning_started'])
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

        $cloudProvider = $team->cloudProvider();

        $server = $team->server()->create([
            'name' => "provision-{$team->id}",
            'cloud_provider' => $cloudProvider,
            // Set region based on provider so the stored value reflects where
            // the droplet will actually be created — not the Hetzner-centric
            // 'nbg1' migration default. Fixes #30.
            'region' => $cloudProvider->defaultProviderRegion(),
            // Same story for server_type: the provision jobs size the real
            // machine from Team::serverType(), so the stored value must match
            // rather than keeping the 'cx32' column default.
            'server_type' => $team->serverType(),
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

        // Fence agent-originated writes before the queued teardown can wait.
        // The job repeats this idempotently for CLI/direct dispatches.
        $team->server?->update(['status' => ServerStatus::Destroying]);
        $team->agents()->update(['status' => AgentStatus::Paused->value]);
        AgentApiToken::query()->where('team_id', $team->id)->delete();

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
