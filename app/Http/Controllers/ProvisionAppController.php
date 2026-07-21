<?php

namespace App\Http\Controllers;

use App\Enums\AgentStatus;
use App\Enums\HarnessType;
use App\Enums\ServerStatus;
use App\Exceptions\MobilePairingFailedException;
use App\Exceptions\MobilePairingUnavailableException;
use App\Models\Agent;
use App\Models\MobilePairingHandoff;
use App\Services\MobilePairingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProvisionAppController extends Controller
{
    public function __construct(private readonly MobilePairingService $pairingService) {}

    public function show(Request $request, Agent $agent): Response
    {
        $this->authorizeAgent($request, $agent);
        $agent->loadMissing('server');

        return Inertia::render('agents/provision-app', [
            'agent' => [
                'id' => $agent->id,
                'name' => $agent->name,
                'emoji' => $agent->emoji,
            ],
            'canPair' => $this->canPair($agent),
            'unavailableReason' => $this->unavailableReason($agent),
        ]);
    }

    public function storeHandoff(Request $request, Agent $agent): JsonResponse
    {
        $this->authorizeAgent($request, $agent);
        $agent->loadMissing('server');

        if (! $this->canPair($agent)) {
            return response()->json([
                'message' => $this->unavailableReason($agent),
            ], 422);
        }

        try {
            $handoff = $this->pairingService->createHandoff($agent, $request->user());
        } catch (MobilePairingUnavailableException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        } catch (MobilePairingFailedException) {
            return response()->json([
                'message' => 'Provision could not prepare secure mobile pairing. Please try again.',
            ], 503);
        }

        return response()->json($handoff, 201);
    }

    public function showHandoff(Request $request, Agent $agent, MobilePairingHandoff $handoff): JsonResponse
    {
        $this->authorizeAgent($request, $agent);

        abort_unless($handoff->agent_id === $agent->id && $handoff->team_id === $agent->team_id, 404);

        return response()->json([
            'status' => $handoff->status(),
            'expiresAt' => $handoff->expires_at->toIso8601String(),
        ]);
    }

    private function authorizeAgent(Request $request, Agent $agent): void
    {
        $team = $request->user()->currentTeam;

        abort_unless($team !== null && $agent->team_id === $team->id, 404);
        abort_unless($request->user()->isTeamAdmin($team), 403);
    }

    private function canPair(Agent $agent): bool
    {
        return $agent->harness_type === HarnessType::OpenClaw
            && $agent->status === AgentStatus::Active
            && $agent->server !== null
            && $agent->server->status === ServerStatus::Running
            && ! $agent->server->isDocker();
    }

    private function unavailableReason(Agent $agent): ?string
    {
        if ($agent->harness_type !== HarnessType::OpenClaw) {
            return 'Provision App pairing is currently available for OpenClaw agents only.';
        }

        if ($agent->status !== AgentStatus::Active) {
            return 'Wait for this agent to finish provisioning before pairing the app.';
        }

        if ($agent->server === null || $agent->server->status !== ServerStatus::Running) {
            return 'The agent server must be running before you can pair the app.';
        }

        if ($agent->server->isDocker()) {
            return 'Provision App pairing requires a publicly reachable agent server and is not available for local Docker agents.';
        }

        return null;
    }
}
