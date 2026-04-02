<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Services\SshService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AgentSessionController extends Controller
{
    public function __construct(public SshService $sshService) {}

    public function index(Agent $agent, Request $request): JsonResponse
    {
        $team = $request->user()->currentTeam;

        abort_unless($agent->team_id === $team->id, 404);

        $server = $agent->server;

        if (! $server) {
            return response()->json(['error' => 'Agent has no server.'], 422);
        }

        try {
            $sessions = Cache::remember("agent-sessions-{$agent->id}", 30, function () use ($agent, $server) {
                $this->sshService->connect($server);

                $path = "/mnt/openclaw-data/agents/{$agent->harness_agent_id}/sessions/sessions.json";
                $content = $this->sshService->readFile($path);
                $this->sshService->disconnect();

                $data = json_decode($content, true) ?? [];

                // sessions.json is a keyed object: { "session-id": { ...session data } }
                $mapped = collect($data)->map(fn (array $session, string $key) => [
                    'session_id' => $key,
                    'inputTokens' => $session['inputTokens'] ?? 0,
                    'outputTokens' => $session['outputTokens'] ?? 0,
                    'updatedAt' => $session['updatedAt'] ?? null,
                    'sessionFile' => $session['sessionFile'] ?? null,
                ])->sortByDesc('updatedAt')->values()->all();

                return $mapped;
            });

            return response()->json(['sessions' => $sessions]);
        } catch (\Throwable $e) {
            Log::error('Failed to fetch agent sessions', [
                'agent_id' => $agent->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Failed to fetch sessions from server.'], 500);
        }
    }

    public function show(Agent $agent, string $sessionId, Request $request): JsonResponse
    {
        $team = $request->user()->currentTeam;

        abort_unless($agent->team_id === $team->id, 404);

        $server = $agent->server;

        if (! $server) {
            return response()->json(['error' => 'Agent has no server.'], 422);
        }

        try {
            $this->sshService->connect($server);

            $sessionsPath = "/mnt/openclaw-data/agents/{$agent->harness_agent_id}/sessions/sessions.json";
            $sessionsContent = $this->sshService->readFile($sessionsPath);
            $sessions = json_decode($sessionsContent, true) ?? [];

            // sessions.json is keyed by session ID
            $session = $sessions[$sessionId] ?? null;

            if (! $session || empty($session['sessionFile'])) {
                $this->sshService->disconnect();

                return response()->json(['error' => 'Session not found.'], 404);
            }

            $jsonlContent = $this->sshService->readFile($session['sessionFile']);
            $this->sshService->disconnect();

            $lines = array_filter(explode("\n", trim($jsonlContent)));
            $messages = collect($lines)
                ->map(fn (string $line) => json_decode($line, true))
                ->filter(fn ($entry) => $entry && ($entry['type'] ?? null) === 'message')
                ->map(function (array $entry) {
                    $msg = $entry['message'] ?? $entry;

                    return [
                        'role' => $msg['role'] ?? 'unknown',
                        'content' => $this->extractTextContent($msg['content'] ?? ''),
                        'timestamp' => $entry['timestamp'] ?? $msg['timestamp'] ?? null,
                    ];
                })
                ->values();

            $page = max(1, (int) $request->query('page', '1'));
            $perPage = 100;
            $total = $messages->count();
            $pageMessages = $messages->slice(($page - 1) * $perPage, $perPage)->values();

            return response()->json([
                'messages' => $pageMessages,
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to fetch agent session', [
                'agent_id' => $agent->id,
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Failed to fetch session from server.'], 500);
        }
    }

    private function extractTextContent(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }

        if (is_array($content)) {
            return collect($content)
                ->filter(fn ($block) => is_array($block) && ($block['type'] ?? null) === 'text')
                ->pluck('text')
                ->implode("\n");
        }

        return '';
    }
}
