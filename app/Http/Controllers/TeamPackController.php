<?php

namespace App\Http\Controllers;

use App\Enums\AgentStatus;
use App\Events\AgentActivityEvent;
use App\Jobs\GenerateAgentAvatarJob;
use App\Models\AgentActivity;
use App\Models\Team;
use App\Models\TeamPack;
use App\Services\AgentTemplateService;
use App\Support\Provision;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Provision\MailboxKit\Services\EmailProvisioningService;

class TeamPackController extends Controller
{
    public function hire(Request $request, TeamPack $pack, EmailProvisioningService $emailService, AgentTemplateService $templateService): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        abort_unless($request->user()->isTeamAdmin($team), 403);

        $billingModel = Provision::teamModel();
        $bt = ($billingModel !== Team::class && ! $team instanceof $billingModel)
            ? ($billingModel::find($team->id) ?? $team)
            : $team;

        if (method_exists($bt, 'subscribed')) {
            abort_unless($bt->subscribed('default'), 403, 'A subscription is required to create agents.');
        }

        $pack->load('templates');

        if (method_exists($bt, 'agentLimit')) {
            $currentCount = $team->agents()->count();
            $remainingSlots = $bt->agentLimit() - $currentCount;
            abort_unless($pack->templates->count() <= $remainingSlots, 422,
                "This pack has {$pack->templates->count()} agents but you only have {$remainingSlots} slots remaining on your plan.");
        }

        $userContext = $templateService->generateUserContext($request->user(), $team);
        $createdAgents = [];

        foreach ($pack->templates as $template) {
            $agent = $team->agents()->create([
                'agent_template_id' => $template->id,
                'server_id' => $team->server?->id,
                'name' => $template->name,
                'role' => $template->role,
                'status' => AgentStatus::Pending,
                'system_prompt' => $template->system_prompt,
                'identity' => $template->identity,
                'soul' => $template->soul,
                'tools_config' => $template->tools_config,
                'user_context' => $userContext,
                'model_primary' => $template->model_primary,
                'model_fallbacks' => $template->model_fallbacks,
                'harness_agent_id' => strtolower(Str::ulid()->toBase32()),
            ]);

            $email = $emailService->provisionEmail($agent, $team);
            if ($email) {
                $agent->update([
                    'identity' => $emailService->injectEmailIntoIdentity($agent->identity, $email),
                ]);
            }

            GenerateAgentAvatarJob::dispatch($agent);

            $activity = AgentActivity::create([
                'agent_id' => $agent->id,
                'type' => 'agent_hired',
                'summary' => "Hired {$agent->name} from {$pack->name} pack",
            ]);

            AgentActivityEvent::dispatch($activity);

            $createdAgents[] = $agent;
        }

        return to_route('agents.index')->with('flash', [
            'message' => "Hired {$pack->name} pack — {$pack->templates->count()} agents created.",
        ]);
    }
}
