<?php

namespace App\Http\Controllers;

use App\Jobs\RestartGatewayJob;
use App\Models\Agent;
use App\Services\SshService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class AgentEnvController extends Controller
{
    public function __construct(private SshService $sshService) {}

    public function show(Agent $agent, Request $request): JsonResponse
    {
        $this->authorizeAgent($agent, $request);

        $server = $agent->server;
        if (! $server) {
            return response()->json(['content' => '']);
        }

        $this->sshService->connect($server);

        try {
            $path = "/root/.openclaw/agents/{$agent->harness_agent_id}/.env";
            $content = $this->sshService->readFile($path);
        } catch (RuntimeException) {
            $content = '';
        } finally {
            $this->sshService->disconnect();
        }

        return response()->json(['content' => $content]);
    }

    public function update(Agent $agent, Request $request): JsonResponse
    {
        $this->authorizeAgent($agent, $request);

        $validated = $request->validate([
            'content' => ['required', 'string', 'max:10000'],
        ]);

        $server = $agent->server;
        abort_unless($server, 404);

        $this->sshService->connect($server);

        try {
            $path = "/root/.openclaw/agents/{$agent->harness_agent_id}/.env";
            $this->sshService->writeFile($path, $validated['content']);
        } finally {
            $this->sshService->disconnect();
        }

        RestartGatewayJob::dispatch($server);

        return response()->json(['success' => true]);
    }

    private function authorizeAgent(Agent $agent, Request $request): void
    {
        abort_unless($agent->team_id === $request->user()->currentTeam->id, 404);
    }
}
