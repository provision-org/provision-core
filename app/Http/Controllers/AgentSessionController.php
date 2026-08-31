<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Services\SshService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Read-only session browser backed by the gateway's sessions.list and
 * chat.history RPCs. OpenClaw 2026.8.1 moved the session store from
 * sessions/sessions.json into per-agent SQLite, so the RPC surface is the
 * only stable read path — direct file reads return nothing on 2026.8.1+.
 */
class AgentSessionController extends Controller
{
    private const GATEWAY_TIMEOUT_MS = 15000;

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

                try {
                    $result = $this->callGateway('sessions.list', [
                        'agentId' => $agent->harness_agent_id,
                        'limit' => 200,
                        'sortBy' => 'updatedAt',
                        'includeDerivedTitles' => true,
                    ]);
                } finally {
                    $this->sshService->disconnect();
                }

                $rows = $result['sessions'] ?? $result['rows'] ?? [];

                return collect(is_array($rows) ? $rows : [])
                    ->filter(fn ($row) => is_array($row) && isset($row['key']))
                    ->map(fn (array $row) => [
                        'session_id' => $row['key'],
                        'title' => $row['title'] ?? $row['derivedTitle'] ?? null,
                        'inputTokens' => $row['inputTokens'] ?? 0,
                        'outputTokens' => $row['outputTokens'] ?? 0,
                        'updatedAt' => $row['updatedAt'] ?? null,
                        'hasActiveRun' => (bool) ($row['hasActiveRun'] ?? false),
                    ])
                    ->sortByDesc('updatedAt')
                    ->values()
                    ->all();
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

            try {
                $history = $this->callGateway('chat.history', [
                    'sessionKey' => $sessionId,
                    'agentId' => $agent->harness_agent_id,
                    'limit' => 1000,
                ]);
            } finally {
                $this->sshService->disconnect();
            }

            $messages = collect($history['messages'] ?? [])
                ->filter(fn ($entry) => is_array($entry))
                ->map(function (array $entry) {
                    // chat.history replays session.message rows; tolerate both
                    // the flat shape and a nested `message` envelope.
                    $msg = is_array($entry['message'] ?? null) ? $entry['message'] : $entry;

                    return [
                        'role' => $msg['role'] ?? 'unknown',
                        'content' => $this->extractTextContent($msg['content'] ?? ($msg['text'] ?? '')),
                        'timestamp' => $entry['timestamp'] ?? $msg['timestamp'] ?? null,
                    ];
                })
                ->filter(fn (array $msg) => $msg['content'] !== '')
                ->values();

            if ($messages->isEmpty() && empty($history['sessionInfo'])) {
                return response()->json(['error' => 'Session not found.'], 404);
            }

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

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function callGateway(string $method, array $params): array
    {
        $payload = json_encode($params, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $output = trim($this->sshService->exec(
            'openclaw gateway call '.escapeshellarg($method)
            .' --json --params '.escapeshellarg($payload)
            .' --timeout '.self::GATEWAY_TIMEOUT_MS.' 2>&1',
        ));

        $decoded = json_decode($output, true);

        if (! is_array($decoded) || ($decoded['ok'] ?? true) === false || array_key_exists('error', $decoded)) {
            throw new RuntimeException("Gateway {$method} call failed.");
        }

        return $decoded;
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
