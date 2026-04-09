<?php

namespace App\Http\Controllers;

use App\Enums\HarnessType;
use App\Models\Task;
use App\Models\TaskWorkProduct;
use App\Services\SshService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TaskWorkProductController extends Controller
{
    public function __construct(private readonly SshService $sshService) {}

    /**
     * Download a work product file from the agent's workspace on the server.
     */
    public function download(Request $request, Task $task, TaskWorkProduct $taskWorkProduct): StreamedResponse|JsonResponse
    {
        abort_unless($task->team_id === $request->user()->current_team_id, 403);
        abort_unless($taskWorkProduct->task_id === $task->id, 404);
        abort_unless($taskWorkProduct->file_path, 404, 'Work product has no file path.');

        $agent = $task->agent;
        abort_unless($agent, 404, 'Task has no assigned agent.');

        $server = $agent->server;
        abort_unless($server, 404, 'Agent has no server.');

        $basePath = $agent->harness_type === HarnessType::Hermes
            ? "/root/.hermes-{$agent->harness_agent_id}/workspace/"
            : "/root/.openclaw/agents/{$agent->harness_agent_id}/";

        $filePath = $this->sanitizePath($taskWorkProduct->file_path);

        if (! $filePath) {
            return response()->json(['message' => 'Invalid file path.'], 422);
        }

        $fullPath = $basePath.$filePath;

        $this->sshService->connect($server);

        try {
            $content = $this->sshService->readFile($fullPath);
        } catch (RuntimeException) {
            $this->sshService->disconnect();

            return response()->json(['message' => 'File not found on server.'], 404);
        } finally {
            $this->sshService->disconnect();
        }

        $filename = basename($filePath);

        return response()->streamDownload(function () use ($content) {
            echo $content;
        }, $filename);
    }

    /**
     * Sanitize a file path to prevent directory traversal.
     */
    private function sanitizePath(string $path): string
    {
        $path = str_replace("\0", '', $path);
        $path = str_replace('\\', '/', $path);
        $path = ltrim($path, '/');

        $segments = explode('/', $path);
        $clean = [];
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                continue;
            }
            $clean[] = $segment;
        }

        return implode('/', $clean);
    }
}
