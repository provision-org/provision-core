<?php

namespace App\Http\Controllers;

use App\Concerns\TracksEvents;
use App\Contracts\Modules\AgentEmailProvider;
use App\Contracts\Modules\BillingProvider;
use App\Enums\AgentRole;
use App\Enums\AgentStatus;
use App\Enums\HarnessType;
use App\Enums\LlmProvider;
use App\Enums\ModelTier;
use App\Enums\ServerStatus;
use App\Events\AgentUpdatedEvent;
use App\Http\Requests\Settings\CreateAgentRequest;
use App\Http\Requests\Settings\UpdateAgentRequest;
use App\Jobs\CreateAgentOnServerJob;
use App\Jobs\GenerateAgentAvatarJob;
use App\Jobs\ProvisionApiKeyJob;
use App\Jobs\RemoveAgentFromServerJob;
use App\Jobs\RestartGatewayJob;
use App\Jobs\UpdateAgentOnServerJob;
use App\Jobs\VerifyAgentChannelsJob;
use App\Mail\AgentDeletedMail;
use App\Models\Agent;
use App\Models\AgentActivity;
use App\Models\AgentDailyStat;
use App\Models\AgentTemplate;
use App\Models\Team;
use App\Models\TeamPack;
use App\Services\AgentInstallScriptService;
use App\Services\AgentTemplateService;
use App\Services\Harness\HermesDriver;
use App\Services\ModuleRegistry;
use App\Services\SlackAppCleanupService;
use App\Services\SshService;
use App\Support\Provision;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Provision\MailboxKit\Services\EmailProvisioningService;
use Provision\Skills\Models\Skill;

class AgentController extends Controller
{
    use TracksEvents;

    public function index(Request $request): Response
    {
        $team = $request->user()->currentTeam;
        $hasBilling = app()->bound(BillingProvider::class);
        $bt = $hasBilling ? $this->billingTeam($team) : $team;

        return Inertia::render('agents/index', [
            'agents' => $team->agents()->with('server', 'slackConnection', 'emailConnection', 'telegramConnection', 'discordConnection')->get(),
            'server' => $team->server,
            'canCreateAgent' => $hasBilling ? $bt->canCreateAgent() : true,
            'agentLimit' => $hasBilling ? $bt->agentLimit() : null,
            'currentPlan' => $hasBilling ? $bt->plan?->value : null,
            'hasBilling' => $hasBilling,
        ]);
    }

    public function library(Request $request): Response
    {
        $team = $request->user()->currentTeam;
        $hasBilling = app()->bound(BillingProvider::class);
        $bt = $hasBilling ? $this->billingTeam($team) : $team;
        $seatPrice = $hasBilling ? ($bt->plan?->agentSeatPriceCents() ?? 9900) : 0;
        $planPrice = $hasBilling ? ($bt->plan?->monthlyPriceCents() ?? 9900) : 0;
        $currentAgentCount = $team->agents()->count();
        $includedAgents = $hasBilling ? ($bt->plan?->includedAgents() ?? 1) : null;
        $extraSeats = $hasBilling ? $bt->extraAgentSeats() : 0;
        $isOnTrial = $hasBilling && $bt->isOnTrial();
        $trialEndsAt = $hasBilling ? $bt->subscription('default')?->trial_ends_at : null;

        return Inertia::render('agents/library', [
            'templates' => AgentTemplate::query()->active()->orderBy('sort_order')->get([
                'id', 'slug', 'name', 'tagline', 'emoji', 'role', 'avatar_path', 'sort_order',
            ]),
            'packs' => TeamPack::query()->active()->with('templates:id,slug,name,emoji,role,avatar_path')->orderBy('sort_order')->get(),
            'canCreateAgent' => $hasBilling ? $bt->canCreateAgent() : true,
            'agentLimit' => $hasBilling ? $bt->agentLimit() : null,
            'currentPlan' => $hasBilling ? $bt->plan?->value : null,
            'hasBilling' => $hasBilling,
            'seatPriceCents' => $seatPrice,
            'planPriceCents' => $planPrice,
            'currentAgentCount' => $currentAgentCount,
            'includedAgents' => $includedAgents,
            'extraSeats' => $extraSeats,
            'isOnTrial' => $isOnTrial,
            'trialEndsAt' => $trialEndsAt?->toISOString(),
        ]);
    }

    public function create(Request $request): Response|RedirectResponse
    {
        $team = $request->user()->currentTeam;

        if (! $team->server || $team->server->status !== ServerStatus::Running) {
            return to_route('teams.provisioning', $team);
        }

        $hasBilling = app()->bound(BillingProvider::class);
        $bt = $hasBilling ? $this->billingTeam($team) : $team;
        $needsSeat = $hasBilling && ! $bt->canCreateAgent() && $bt->availableExtraSeats() > 0;
        $seatPrice = $hasBilling ? ($bt->plan?->agentSeatPriceCents() ?? 9900) : 0;
        $planPrice = $hasBilling ? ($bt->plan?->monthlyPriceCents() ?? 9900) : 0;
        $extraSeats = $hasBilling ? $bt->extraAgentSeats() : 0;
        $isOnTrial = $hasBilling && $bt->isOnTrial();
        $trialEndsAt = $hasBilling ? $bt->subscription('default')?->trial_ends_at : null;

        return Inertia::render('agents/create', [
            'server' => $team->server,
            'availableModels' => $this->availableModels($team),
            'defaultModel' => LlmProvider::DEFAULT_MODEL,
            'modelTiers' => collect(ModelTier::cases())->map(fn (ModelTier $tier) => [
                'value' => $tier->value,
                'label' => $tier->label(),
                'description' => $tier->description(),
                'cost' => $tier->estimatedMonthlyCost(),
                'primaryModel' => $tier->primaryModel(),
            ])->values()->all(),
            'defaultTier' => ModelTier::Powerful->value,
            'canCreateAgent' => $hasBilling ? $bt->canCreateAgent() : true,
            'needsSeat' => $needsSeat,
            'seatPriceMonthly' => number_format($seatPrice / 100, 0),
            'agentLimit' => $hasBilling ? $bt->agentLimit() : null,
            'currentPlan' => $hasBilling ? $bt->plan?->value : null,
            'hasBilling' => $hasBilling,
            'emailDomain' => app()->bound(AgentEmailProvider::class) ? config('mailboxkit.email_domain') : null,
            'teamSlug' => Str::slug($team->name, '_'),
            'isOnTrial' => $isOnTrial,
            'trialEndsAt' => $trialEndsAt?->toISOString(),
            'planPriceCents' => $planPrice,
            'extraSeats' => $extraSeats,
        ]);
    }

    public function store(CreateAgentRequest $request, AgentTemplateService $templateService): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        abort_unless($request->user()->isTeamAdmin($team), 403);

        $billing = app()->bound(BillingProvider::class) ? app(BillingProvider::class) : null;

        if ($billing) {
            $bt = $this->billingTeam($team);
            if ($billing->requiresSubscription() && ! $bt->subscribed('default')) {
                return to_route('subscribe');
            }

            if (! $billing->canCreateAgent($team)) {
                abort_unless($bt->availableExtraSeats() > 0, 422, 'You have reached the maximum agent limit for your plan.');

                try {
                    $this->purchaseAgentSeat($bt);
                } catch (\Throwable $e) {
                    Log::error('Failed to purchase agent seat', ['team_id' => $team->id, 'error' => $e->getMessage()]);

                    return back()->withErrors(['seat' => 'Failed to add agent seat. Please try again or contact support.']);
                }
            }
        }

        $server = $team->server;

        if (! $server || $server->status !== ServerStatus::Running) {
            return to_route('teams.provisioning', $team);
        }
        $data = $request->validated();

        // Resolve model tier to primary model + fallbacks
        if (! empty($data['model_tier'])) {
            $tier = ModelTier::from($data['model_tier']);
            $data['model_primary'] = $tier->primaryModel();
            $data['model_fallbacks'] = $tier->fallbackModels();
        }
        unset($data['model_tier']);

        if (empty($data['user_context'])) {
            $data['user_context'] = $templateService->generateUserContext($request->user(), $team);
        }

        // Generate AGENTS.md (system_prompt) from job description if not provided
        if (empty($data['system_prompt']) && ! empty($data['job_description'])) {
            $name = $data['name'];
            $jobDesc = $data['job_description'];
            $data['system_prompt'] = "# {$name} — Agent Instructions\n\n## Role\n\n{$jobDesc}\n\n## Operating Principles\n\n- Take initiative. Don't wait for permission on routine tasks.\n- Report back with results, not questions about how to start.\n- When blocked, try an alternative approach before escalating.\n- Keep your team informed of progress on long-running tasks.\n- Save all work output to your workspace so your team can review it.";
        }

        // Generate SOUL.md from personality + job description if not provided
        if (empty($data['soul']) && ! empty($data['job_description'])) {
            $name = $data['name'];
            $personality = $data['personality'] ?? 'professional and proactive';
            $style = $data['communication_style'] ?? 'clear and concise';
            $data['soul'] = "# {$name} — Soul\n\n## Personality\n{$personality}\n\n## Communication Style\n{$style}\n\n## Work Ethic\n- Be thorough but efficient. Don't over-explain unless asked.\n- Own your domain. Develop expertise and opinions about your area of work.\n- Be honest about limitations. If you can't do something well, say so.\n- Learn from feedback. Adapt your approach based on what your team tells you.";
        }

        if (empty($data['identity'])) {
            $data['identity'] = $templateService->generateIdentity(
                name: $data['name'],
                role: AgentRole::tryFrom($data['role'] ?? ''),
                emoji: $data['emoji'] ?? '',
                personality: $data['personality'] ?? '',
                style: $data['communication_style'] ?? '',
                backstory: $data['backstory'] ?? '',
            );
        }

        // Generate TOOLS.md header from job description if not provided
        if (empty($data['tools_config']) && ! empty($data['job_description'])) {
            $name = $data['name'];
            $data['tools_config'] = "# {$name} — Tools & Capabilities\n\n## Your Job\n\n{$data['job_description']}\n\n## How to Work\n\n- Use `exec` to run shell commands, curl API calls, and scripts.\n- Use your browser to research, navigate websites, and extract data.\n- Use your email to communicate externally when needed.\n- Save all output files to your workspace directory.";
        }

        $emailPrefix = $data['email_prefix'] ?? null;
        unset($data['email_prefix']);

        $tools = $data['tools'] ?? [];
        unset($data['tools']);

        $defaultPassword = Agent::generateSecurePassword();

        // Auto-fill harness_type from team (user doesn't choose per-agent)
        unset($data['harness_type']);

        $agent = $team->agents()->create(array_merge($data, [
            'server_id' => $server?->id,
            'harness_type' => $team->harness_type ?? HarnessType::Hermes,
            'harness_agent_id' => strtolower(Str::ulid()->toBase32()),
            'status' => AgentStatus::Pending,
            'default_password' => $defaultPassword,
        ]));

        foreach ($tools as $tool) {
            $agent->tools()->create([
                'name' => $tool['name'],
                'url' => $tool['url'] ?? null,
            ]);
        }

        $emailProvider = app()->bound(AgentEmailProvider::class) ? app(AgentEmailProvider::class) : null;
        if ($emailProvider) {
            $email = $emailProvider->provisionEmail($agent, $team, $emailPrefix);
            if ($email) {
                $agent->update([
                    'identity' => $templateService->generateIdentity(
                        name: $agent->name,
                        role: $agent->role,
                        email: $email,
                        emoji: $data['emoji'] ?? '',
                        personality: $data['personality'] ?? '',
                        style: $data['communication_style'] ?? '',
                        backstory: $data['backstory'] ?? '',
                    ),
                ]);
            }
        }

        GenerateAgentAvatarJob::dispatch($agent);

        $this->mixpanel()->track($request->user(), 'Agent Created', [
            'agent_name' => $agent->name,
            'role' => $agent->role?->value ?? 'custom',
            'model' => $agent->model_primary,
        ]);

        return to_route('agents.setup', $agent);
    }

    public function hire(Request $request, AgentTemplate $agentTemplate, AgentTemplateService $templateService): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        abort_unless($request->user()->isTeamAdmin($team), 403);
        abort_unless($agentTemplate->is_active, 404);

        $billing = app()->bound(BillingProvider::class) ? app(BillingProvider::class) : null;

        if ($billing) {
            $bt = $this->billingTeam($team);
            if ($billing->requiresSubscription() && ! $bt->subscribed('default')) {
                return to_route('subscribe');
            }

            if (! $billing->canCreateAgent($team)) {
                abort_unless($bt->availableExtraSeats() > 0, 422, 'You have reached the maximum agent limit for your plan.');

                try {
                    $this->purchaseAgentSeat($bt);
                } catch (\Throwable $e) {
                    Log::error('Failed to purchase agent seat', ['team_id' => $team->id, 'error' => $e->getMessage()]);

                    return back()->withErrors(['seat' => 'Failed to add agent seat. Please try again or contact support.']);
                }
            }
        }

        if ($team->agents()->where('name', $agentTemplate->name)->exists()) {
            return back()->withErrors(['name' => "An agent named \"{$agentTemplate->name}\" already exists on this team."]);
        }

        $this->ensureTeamHasServer($team);

        $defaultPassword = Agent::generateSecurePassword();

        $agent = $team->agents()->create([
            'agent_template_id' => $agentTemplate->id,
            'server_id' => $team->fresh()->server?->id,
            'harness_type' => $team->harness_type ?? HarnessType::Hermes,
            'name' => $agentTemplate->name,
            'role' => $agentTemplate->role,
            'status' => AgentStatus::Pending,
            'system_prompt' => $agentTemplate->system_prompt,
            'identity' => $agentTemplate->identity,
            'soul' => $agentTemplate->soul,
            'tools_config' => $agentTemplate->tools_config,
            'user_context' => $templateService->generateUserContext($request->user(), $team),
            'model_primary' => $agentTemplate->model_primary,
            'model_fallbacks' => $agentTemplate->model_fallbacks,
            'harness_agent_id' => strtolower(Str::ulid()->toBase32()),
            'default_password' => $defaultPassword,
        ]);

        $tools = $request->input('tools', $agentTemplate->recommended_tools ?? []);
        foreach ($tools as $tool) {
            $agent->tools()->create([
                'name' => $tool['name'] ?? '',
                'url' => $tool['url'] ?? null,
            ]);
        }

        $emailProvider = app()->bound(AgentEmailProvider::class) ? app(AgentEmailProvider::class) : null;
        if ($emailProvider) {
            $email = $emailProvider->provisionEmail($agent, $team);
            if ($email) {
                $agent->update([
                    'identity' => app(EmailProvisioningService::class)->injectEmailIntoIdentity($agent->identity, $email),
                ]);
            }
        }

        GenerateAgentAvatarJob::dispatch($agent);

        return to_route('agents.setup', $agent);
    }

    public function setup(Request $request, Agent $agent): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        abort_unless($agent->team_id === $team->id, 404);
        abort_unless($request->user()->isTeamAdmin($team), 403);

        if ($agent->status === AgentStatus::Active) {
            return to_route('agents.show', $agent);
        }

        if ($agent->status !== AgentStatus::Pending) {
            return to_route('agents.provisioning', $agent);
        }

        return to_route('agents.channels', $agent);
    }

    public function channels(Request $request, Agent $agent): Response
    {
        $team = $request->user()->currentTeam;

        abort_unless($agent->team_id === $team->id, 404);
        abort_unless($request->user()->isTeamAdmin($team), 403);

        return Inertia::render('agents/channels', [
            'agent' => $agent->load('slackConnection', 'telegramConnection', 'discordConnection'),
        ]);
    }

    public function provisioning(Request $request, Agent $agent): Response|RedirectResponse
    {
        $team = $request->user()->currentTeam;

        abort_unless($agent->team_id === $team->id, 404);

        if ($agent->status === AgentStatus::Active) {
            return to_route('agents.show', $agent);
        }

        if ($agent->status === AgentStatus::Pending) {
            $server = $team->server;

            if ($server && $server->status === ServerStatus::Running) {
                if (config('services.openrouter.provisioning_api_key') && ! $team->managedApiKey()->exists()) {
                    ProvisionApiKeyJob::dispatch($team);
                }

                $agent->update([
                    'status' => AgentStatus::Deploying,
                    'server_id' => $server->id,
                ]);

                try {
                    broadcast(new AgentUpdatedEvent($agent));
                } catch (\Throwable $e) {
                    Log::warning('Failed to broadcast AgentUpdatedEvent', ['agent_id' => $agent->id, 'error' => $e->getMessage()]);
                }

                CreateAgentOnServerJob::dispatch($agent);
            }
        }

        return Inertia::render('agents/provisioning', [
            'agent' => $agent->only('id', 'name', 'status'),
        ]);
    }

    public function show(Request $request, Agent $agent): Response
    {
        $team = $request->user()->currentTeam;

        abort_unless($agent->team_id === $team->id, 404);

        $activities = AgentActivity::query()
            ->where('agent_id', $agent->id)
            ->latest()
            ->limit(20)
            ->get()
            ->map(fn (AgentActivity $a) => [
                'id' => $a->id,
                'agent_id' => $a->agent_id,
                'agent_name' => $agent->name,
                'type' => $a->type,
                'channel' => $a->channel,
                'summary' => $a->summary,
                'created_at' => $a->created_at->toISOString(),
            ]);

        $server = $agent->server;
        $browserUrl = null;
        if ($server?->isDocker()) {
            // Docker mode: shared VNC display at port 6080 (no password)
            $browserUrl = 'http://localhost:6080/vnc.html?autoconnect=true&resize=scale';
        } elseif ($server?->ipv4_address && $server?->vnc_password) {
            $browserUrl = URL::signedRoute('agents.browser', ['agent' => $agent], now()->addMinutes(15));
        }

        return Inertia::render('agents/show', [
            'agent' => $agent->load(array_filter([
                'server', 'slackConnection', 'emailConnection', 'telegramConnection', 'discordConnection', 'tools',
                class_exists(Skill::class) ? 'skills' : null,
            ])),
            'activities' => $activities,
            'teamId' => $team->id,
            'browserUrl' => $browserUrl,
        ]);
    }

    public function configure(Request $request, Agent $agent): Response
    {
        $team = $request->user()->currentTeam;

        abort_unless($agent->team_id === $team->id, 404);

        return Inertia::render('agents/configure', [
            'agent' => $agent->load('server', 'slackConnection', 'emailConnection', 'telegramConnection', 'discordConnection'),
            'availableModels' => $this->availableModels($team),
            'modelTiers' => collect(ModelTier::cases())->map(fn (ModelTier $tier) => [
                'value' => $tier->value,
                'label' => $tier->label(),
                'description' => $tier->description(),
                'cost' => $tier->estimatedMonthlyCost(),
                'primaryModel' => $tier->primaryModel(),
            ])->values()->all(),
        ]);
    }

    public function edit(Request $request, Agent $agent): Response
    {
        $team = $request->user()->currentTeam;

        abort_unless($agent->team_id === $team->id, 404);
        abort_unless($request->user()->isTeamAdmin($team), 403);

        return Inertia::render('agents/edit', [
            'agent' => $agent,
            'availableModels' => $this->availableModels($team),
        ]);
    }

    public function update(UpdateAgentRequest $request, Agent $agent): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        abort_unless($agent->team_id === $team->id, 404);
        abort_unless($request->user()->isTeamAdmin($team), 403);

        $agent->update($request->validated());

        if ($agent->server_id) {
            $agent->update(['is_syncing' => true]);

            try {
                broadcast(new AgentUpdatedEvent($agent));
            } catch (\Throwable $e) {
                Log::warning('Failed to broadcast AgentUpdatedEvent', ['agent_id' => $agent->id, 'error' => $e->getMessage()]);
            }

            UpdateAgentOnServerJob::dispatch($agent);
        }

        return back();
    }

    public function retry(Request $request, Agent $agent): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        abort_unless($agent->team_id === $team->id, 404);
        abort_unless($request->user()->isTeamAdmin($team), 403);
        abort_unless($agent->status === AgentStatus::Error, 422);

        $server = $team->server;

        abort_unless($server && $server->status === ServerStatus::Running, 422);

        $agent->update([
            'server_id' => $server->id,
            'status' => AgentStatus::Deploying,
        ]);

        try {
            broadcast(new AgentUpdatedEvent($agent));
        } catch (\Throwable $e) {
            Log::warning('Failed to broadcast AgentUpdatedEvent', ['agent_id' => $agent->id, 'error' => $e->getMessage()]);
        }

        CreateAgentOnServerJob::dispatch($agent);

        return to_route('agents.provisioning', $agent);
    }

    public function restartGateway(Request $request): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        abort_unless($request->user()->isTeamAdmin($team), 403);

        $server = $team->server;

        abort_unless($server && $server->status === ServerStatus::Running, 422, 'Server is not running.');

        RestartGatewayJob::dispatch($server);

        return back()->with('status', 'Gateway restart initiated. Agents will reconnect in a few seconds.');
    }

    public function resyncChannels(Request $request, Agent $agent): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        abort_unless($agent->team_id === $team->id, 404);
        abort_unless($request->user()->isTeamAdmin($team), 403);
        abort_unless($agent->status === AgentStatus::Active && $agent->server, 422, 'Agent must be active with a server.');

        VerifyAgentChannelsJob::dispatch($agent);

        return back()->with('status', 'Channel re-sync initiated. This will verify and repair the channel configuration on the server.');
    }

    public function destroy(Request $request, Agent $agent, SlackAppCleanupService $slackCleanup): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        abort_unless($agent->team_id === $team->id, 404);
        abort_unless($request->user()->isTeamAdmin($team), 403);

        $agentName = $agent->name;

        $slackCleanup->cleanup($agent);

        if ($agent->server_id && $agent->server) {
            RemoveAgentFromServerJob::dispatch(
                $agent->server,
                $agent->harness_agent_id,
                $agent->slackConnection !== null,
                $agent->harness_type,
            );
        }

        $modules = app(ModuleRegistry::class);
        foreach ($modules->all() as $module) {
            $module->cleanupAgent($agent);
        }

        $remainingEmailAgents = $team->agents()
            ->whereHas('emailConnection')
            ->where('id', '!=', $agent->id)
            ->exists();

        $agent->delete();

        if (! $remainingEmailAgents && app()->bound(AgentEmailProvider::class)) {
            $team->envVars()->where('key', 'MAILBOXKIT_API_KEY')->where('is_system', true)->delete();
        }

        Mail::to($request->user()->email)->send(new AgentDeletedMail($agentName, $team));

        return to_route('agents.index');
    }

    public function usageChart(Request $request, Agent $agent): JsonResponse
    {
        $team = $request->user()->currentTeam;

        abort_unless($agent->team_id === $team->id, 404);

        $days = min((int) $request->query('days', '30'), 90);
        $startDate = now()->subDays($days)->toDateString();

        $snapshots = AgentDailyStat::query()
            ->where('agent_id', $agent->id)
            ->where('date', '>=', $startDate)
            ->orderBy('date')
            ->get();

        // Get baseline: the last snapshot before the window
        $baseline = AgentDailyStat::query()
            ->where('agent_id', $agent->id)
            ->where('date', '<', $startDate)
            ->orderByDesc('date')
            ->first();

        $prevTokensInput = $baseline?->cumulative_tokens_input ?? 0;
        $prevTokensOutput = $baseline?->cumulative_tokens_output ?? 0;
        $prevMessages = $baseline?->cumulative_messages ?? 0;
        $prevSessions = $baseline?->cumulative_sessions ?? 0;

        $data = $snapshots->map(function (AgentDailyStat $stat) use (&$prevTokensInput, &$prevTokensOutput, &$prevMessages, &$prevSessions): array {
            $row = [
                'date' => $stat->date,
                'tokens_input' => max(0, $stat->cumulative_tokens_input - $prevTokensInput),
                'tokens_output' => max(0, $stat->cumulative_tokens_output - $prevTokensOutput),
                'messages' => max(0, $stat->cumulative_messages - $prevMessages),
                'sessions' => max(0, $stat->cumulative_sessions - $prevSessions),
            ];

            $prevTokensInput = $stat->cumulative_tokens_input;
            $prevTokensOutput = $stat->cumulative_tokens_output;
            $prevMessages = $stat->cumulative_messages;
            $prevSessions = $stat->cumulative_sessions;

            return $row;
        })->values();

        return response()->json($data);
    }

    /**
     * Serve the noVNC browser view via a signed URL.
     * The VNC password never reaches the frontend — it's injected server-side.
     */
    public function browser(Request $request, Agent $agent): \Illuminate\Http\Response
    {
        $team = $request->user()->currentTeam;
        abort_unless($team && $agent->team_id === $team->id, 403);

        $server = $agent->server;
        abort_unless($server?->ipv4_address && $server?->vnc_password, 404);

        $hostname = str_replace('.', '-', $server->ipv4_address).'.sslip.io';
        $profileName = match ($agent->harness_type) {
            HarnessType::Hermes => HermesDriver::browserProfileName($agent),
            default => AgentInstallScriptService::browserProfileName($agent),
        };
        $vncUrl = "https://{$hostname}/browser/{$profileName}/vnc.html?".http_build_query([
            'password' => $server->vnc_password,
            'autoconnect' => 'true',
            'resize' => 'scale',
            'path' => "browser/{$profileName}/websockify",
        ]);

        return response(view('browser', ['vncUrl' => $vncUrl]), 200, [
            'Content-Type' => 'text/html',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'no-referrer',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    public function logs(Request $request, Agent $agent, SshService $sshService): JsonResponse
    {
        $team = $request->user()->currentTeam;

        abort_unless($agent->team_id === $team->id, 404);

        $server = $agent->server;

        if (! $server || $agent->status !== AgentStatus::Active) {
            return response()->json(['error' => 'Agent is not active or has no server.'], 422);
        }

        try {
            $sshService->connect($server);
            $logs = $sshService->exec('openclaw logs 2>&1 | tail -200');

            return response()->json(['logs' => $logs]);
        } catch (\Throwable $e) {
            Log::error('Failed to fetch agent logs', [
                'agent_id' => $agent->id,
                'server_id' => $server->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Failed to fetch logs from server.'], 500);
        } finally {
            $sshService->disconnect();
        }
    }

    public function template(Request $request, string $role, AgentTemplateService $templateService): JsonResponse
    {
        $agentRole = AgentRole::tryFrom($role);

        if (! $agentRole) {
            abort(404);
        }

        return response()->json($templateService->getTemplate($agentRole, $request->user(), $request->user()->currentTeam));
    }

    public function templateDetails(Request $request, AgentTemplate $agentTemplate): JsonResponse
    {
        abort_unless($agentTemplate->is_active, 404);

        // Extract core responsibilities from system_prompt
        $capabilities = [];
        if ($agentTemplate->system_prompt) {
            // Parse bullet points from "Core Responsibilities" section
            if (preg_match('/## Core Responsibilities\n((?:- .+\n?)+)/m', $agentTemplate->system_prompt, $matches)) {
                $capabilities = collect(explode("\n", trim($matches[1])))
                    ->filter(fn (string $line) => str_starts_with($line, '- '))
                    ->map(fn (string $line) => ltrim($line, '- '))
                    ->values()
                    ->all();
            }
        }

        return response()->json([
            'capabilities' => $capabilities,
            'recommended_tools' => $agentTemplate->recommended_tools ?? [],
        ]);
    }

    public function inbox(Request $request, Agent $agent): JsonResponse
    {
        $team = $request->user()->currentTeam;

        abort_unless($agent->team_id === $team->id, 404);

        $emailProvider = app()->bound(AgentEmailProvider::class) ? app(AgentEmailProvider::class) : null;
        abort_unless($emailProvider, 404, 'Email module is not installed.');

        $emailConnection = $agent->emailConnection;
        abort_unless($emailConnection?->mailboxkit_inbox_id, 422, 'Agent does not have an email inbox.');

        try {
            $page = max(1, (int) $request->query('page', '1'));

            return response()->json($emailProvider->getInbox($agent, $page));
        } catch (\Throwable $e) {
            Log::error('Failed to fetch inbox messages', [
                'agent_id' => $agent->id,
                'inbox_id' => $emailConnection->mailboxkit_inbox_id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Failed to fetch messages from mail server.'], 502);
        }
    }

    public function inboxMessage(Request $request, Agent $agent, string $messageId): JsonResponse
    {
        $team = $request->user()->currentTeam;

        abort_unless($agent->team_id === $team->id, 404);

        $emailProvider = app()->bound(AgentEmailProvider::class) ? app(AgentEmailProvider::class) : null;
        abort_unless($emailProvider, 404, 'Email module is not installed.');

        $emailConnection = $agent->emailConnection;
        abort_unless($emailConnection?->mailboxkit_inbox_id, 422, 'Agent does not have an email inbox.');

        try {
            return response()->json($emailProvider->getMessage($agent, $messageId));
        } catch (\Throwable $e) {
            Log::error('Failed to fetch inbox message', [
                'agent_id' => $agent->id,
                'inbox_id' => $emailConnection->mailboxkit_inbox_id,
                'message_id' => $messageId,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Failed to fetch message from mail server.'], 502);
        }
    }

    /**
     * Purchase an agent seat on the team's subscription.
     * During trial, adds the price without invoicing (charged after trial ends).
     * After trial, adds the price and invoices immediately.
     */
    private function purchaseAgentSeat(Team $team): void
    {
        $seatPriceId = config('stripe.agent_seat_price_id');
        abort_unless($seatPriceId, 500, 'Agent seat pricing is not configured.');

        $subscription = $team->subscription('default');
        $seatItem = $subscription->items()->where('stripe_price', $seatPriceId)->first();

        if ($seatItem) {
            $subscription->incrementQuantity(1, $seatPriceId);
        } elseif ($subscription->onTrial()) {
            // During trial: add price without invoicing — billed after trial ends
            $subscription->addPrice($seatPriceId, 1);
        } else {
            // After trial: add price and invoice immediately
            $subscription->addPriceAndInvoice($seatPriceId, 1);
        }
    }

    /**
     * @return array<int, array{value: string, label: string, provider: string}>
     */
    private function availableModels(Team $team): array
    {
        $models = [];

        // Subscribed teams get all managed models via OpenRouter
        $bt = app()->bound(BillingProvider::class) ? $this->billingTeam($team) : $team;
        $isSubscribed = method_exists($bt, 'subscribed') && $bt->subscribed('default');
        $hasManagedKey = $team->managedApiKey()->exists();

        if ($isSubscribed || $hasManagedKey) {
            foreach (LlmProvider::allModels() as $modelId) {
                $provider = LlmProvider::forModel($modelId);
                $models[] = [
                    'value' => $modelId,
                    'label' => $modelId,
                    'provider' => $provider?->label() ?? 'OpenRouter',
                ];
            }

            return $models;
        }

        // BYOK-only teams see models from their active API keys
        $activeProviders = $team->apiKeys()
            ->where('is_active', true)
            ->pluck('provider')
            ->map(fn ($p) => $p instanceof LlmProvider ? $p : LlmProvider::from($p))
            ->unique()
            ->values();

        foreach ($activeProviders as $provider) {
            foreach ($provider->models() as $modelId) {
                $models[] = [
                    'value' => $modelId,
                    'label' => $modelId,
                    'provider' => $provider->label(),
                ];
            }
        }

        return $models;
    }

    /**
     * @return array<string>
     */
    public static function allowedModelIds(Team $team): array
    {
        // Resolve to billing team if module is installed
        $billingModel = Provision::teamModel();
        $bt = ($billingModel !== Team::class && ! $team instanceof $billingModel)
            ? ($billingModel::find($team->id) ?? $team)
            : $team;

        // Subscribed teams get all models
        $isSubscribed = method_exists($bt, 'subscribed') && $bt->subscribed('default');
        $hasManagedKey = $team->managedApiKey()->exists();

        if ($isSubscribed || $hasManagedKey) {
            return LlmProvider::allModels();
        }

        // BYOK models
        return $team->apiKeys()
            ->where('is_active', true)
            ->pluck('provider')
            ->map(fn ($p) => $p instanceof LlmProvider ? $p : LlmProvider::from($p))
            ->unique()
            ->flatMap(fn (LlmProvider $provider) => $provider->models())
            ->values()
            ->all();
    }

    /**
     * Resolve a Team to the billing-aware model (BillableTeam) when the billing module is installed.
     * This ensures billing methods like canCreateAgent(), agentLimit(), subscription() are available.
     */
    private function billingTeam(Team $team): Team
    {
        $billingModel = Provision::teamModel();
        if ($billingModel !== Team::class && ! $team instanceof $billingModel) {
            return $billingModel::find($team->id) ?? $team;
        }

        return $team;
    }
}
