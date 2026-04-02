<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Services\AgentScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentScheduleController extends Controller
{
    public function __construct(private AgentScheduleService $scheduleService) {}

    public function index(Agent $agent, Request $request): JsonResponse
    {
        $this->authorizeAgent($agent, $request);

        if (! $agent->server) {
            return response()->json([]);
        }

        return response()->json($this->scheduleService->list($agent));
    }

    public function store(Agent $agent, Request $request): JsonResponse
    {
        $this->authorizeAgent($agent, $request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'every' => ['required', 'string', 'max:20'],
            'message' => ['required', 'string', 'max:1000'],
            'model' => ['nullable', 'string', 'max:100'],
        ]);

        $result = $this->scheduleService->create(
            $agent,
            $validated['name'],
            $validated['every'],
            $validated['message'],
            $validated['model'] ?? null,
        );

        return response()->json($result, 201);
    }

    public function update(Agent $agent, string $cronId, Request $request): JsonResponse
    {
        $this->authorizeAgent($agent, $request);
        $this->abortIfSystemCron($agent, $cronId);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'every' => ['sometimes', 'string', 'max:20'],
            'message' => ['sometimes', 'string', 'max:1000'],
        ]);

        $result = $this->scheduleService->edit($agent, $cronId, $validated);

        return response()->json($result);
    }

    public function destroy(Agent $agent, string $cronId, Request $request): JsonResponse
    {
        $this->authorizeAgent($agent, $request);
        $this->abortIfSystemCron($agent, $cronId);

        $result = $this->scheduleService->delete($agent, $cronId);

        return response()->json($result);
    }

    public function toggle(Agent $agent, string $cronId, Request $request): JsonResponse
    {
        $this->authorizeAgent($agent, $request);

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        $this->scheduleService->toggle($agent, $cronId, $validated['enabled']);

        return response()->json(['success' => true]);
    }

    public function run(Agent $agent, string $cronId, Request $request): JsonResponse
    {
        $this->authorizeAgent($agent, $request);

        $this->scheduleService->run($agent, $cronId);

        return response()->json(['success' => true]);
    }

    private function authorizeAgent(Agent $agent, Request $request): void
    {
        abort_unless($agent->team_id === $request->user()->currentTeam->id, 404);
    }

    private function abortIfSystemCron(Agent $agent, string $cronId): void
    {
        $crons = $this->scheduleService->list($agent);
        $cron = collect($crons)->firstWhere('id', $cronId);

        abort_if($cron && ($cron['description'] ?? '') === 'system', 403, 'System schedules cannot be modified.');
    }
}
