<?php

namespace App\Http\Controllers;

use App\Events\WorkspaceUpdatedEvent;
use App\Models\Agent;
use App\Services\SshService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AgentWorkspaceController extends Controller
{
    private const MAX_BYTES_PER_SCOPE = 52_428_800; // 50 MB

    private const MAX_FILE_SIZE = 10_485_760; // 10 MB

    private const MAX_FILES_PER_UPLOAD = 20;

    private const CACHE_TTL = 60; // seconds

    private const ALLOWED_EXTENSIONS = [
        'md', 'txt', 'csv', 'json', 'pdf', 'docx', 'doc',
        'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp',
        'py', 'js', 'ts', 'jsx', 'tsx', 'php', 'rb', 'go',
        'html', 'css', 'xml', 'yaml', 'yml', 'toml',
        'sh', 'bash', 'sql', 'env', 'log', 'ini', 'conf',
    ];

    /** OpenClaw system files and internal directories hidden from the dashboard. */
    private const HIDDEN_SYSTEM_FILES = [
        'AGENTS.md', 'BOOTSTRAP.md', 'HEARTBEAT.md',
        'IDENTITY.md', 'SOUL.md', 'TOOLS.md', 'USER.md',
        'agent', 'memory', 'projects', 'sessions',
    ];

    public function __construct(private SshService $sshService) {}

    public function index(Agent $agent, Request $request): JsonResponse
    {
        $this->authorizeAgent($agent, $request);

        $server = $agent->server;
        if (! $server) {
            return response()->json(['files' => [], 'usage' => 0, 'limit' => (method_exists($agent->team, 'storageLimitBytes') ? $agent->team->storageLimitBytes() : 52_428_800), 'cached' => false]);
        }

        $fresh = $request->boolean('fresh');
        $cacheKey = $this->cacheKey($agent);

        if (! $fresh) {
            $cached = Cache::get($cacheKey);
            if ($cached) {
                return response()->json([...$cached, 'cached' => true, 'limit' => (method_exists($agent->team, 'storageLimitBytes') ? $agent->team->storageLimitBytes() : 52_428_800)]);
            }
        }

        $data = $this->fetchFromServer($agent);

        Cache::put($cacheKey, $data, self::CACHE_TTL);

        return response()->json([...$data, 'cached' => false, 'limit' => (method_exists($agent->team, 'storageLimitBytes') ? $agent->team->storageLimitBytes() : 52_428_800)]);
    }

    public function upload(Agent $agent, Request $request): JsonResponse
    {
        $this->authorizeAgent($agent, $request);

        $request->validate([
            'files' => ['required', 'array', 'max:'.self::MAX_FILES_PER_UPLOAD],
            'files.*' => ['required', 'file', 'max:'.(self::MAX_FILE_SIZE / 1024)],
            'path' => ['nullable', 'string', 'max:500'],
        ]);

        $server = $agent->server;
        abort_unless($server, 404);

        $basePath = $this->basePath($agent);
        $subPath = $this->sanitizePath($request->input('path') ?? '');

        $this->sshService->connect($server);

        try {
            // Ensure directory exists
            $targetDir = $subPath ? "{$basePath}/{$subPath}" : $basePath;
            $this->sshService->exec('mkdir -p '.escapeshellarg($targetDir));

            // Check usage
            $usageOutput = trim($this->sshService->exec(
                'du -sb '.escapeshellarg($basePath).' 2>/dev/null | cut -f1 || echo 0'
            ));
            $currentUsage = (int) $usageOutput;

            $totalUploadSize = 0;
            foreach ($request->file('files') as $file) {
                $totalUploadSize += $file->getSize();
            }

            $storageLimit = (method_exists($agent->team, 'storageLimitBytes') ? $agent->team->storageLimitBytes() : 52_428_800);
            if ($currentUsage + $totalUploadSize > $storageLimit) {
                $limitMb = (int) ($storageLimit / 1_048_576);

                return response()->json([
                    'message' => "Upload would exceed the {$limitMb} MB storage limit for your plan.",
                ], 422);
            }

            // Validate extensions and upload
            $uploaded = [];
            foreach ($request->file('files') as $file) {
                $extension = strtolower($file->getClientOriginalExtension());
                if (! in_array($extension, self::ALLOWED_EXTENSIONS)) {
                    continue;
                }

                $filename = $this->sanitizePath($file->getClientOriginalName());
                if (! $filename) {
                    continue;
                }

                $remotePath = "{$targetDir}/{$filename}";
                $this->sshService->upload($file->getRealPath(), $remotePath);
                $uploaded[] = $filename;
            }
        } finally {
            $this->sshService->disconnect();
        }

        $this->bustCache($agent);
        try {
            broadcast(new WorkspaceUpdatedEvent($agent));
        } catch (\Throwable $e) {
            Log::warning('Failed to broadcast WorkspaceUpdatedEvent', ['agent_id' => $agent->id, 'error' => $e->getMessage()]);
        }

        return response()->json([
            'uploaded' => $uploaded,
            'count' => count($uploaded),
        ]);
    }

    public function createFolder(Agent $agent, Request $request): JsonResponse
    {
        $this->authorizeAgent($agent, $request);

        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'path' => ['nullable', 'string', 'max:500'],
        ]);

        $server = $agent->server;
        abort_unless($server, 404);

        $basePath = $this->basePath($agent);
        $subPath = $this->sanitizePath($request->input('path') ?? '');
        $folderName = $this->sanitizePath($request->input('name') ?? '');

        if (! $folderName) {
            return response()->json(['message' => 'Invalid folder name.'], 422);
        }

        $targetDir = $subPath ? "{$basePath}/{$subPath}/{$folderName}" : "{$basePath}/{$folderName}";

        $this->sshService->connect($server);

        try {
            $this->sshService->exec('mkdir -p '.escapeshellarg($targetDir));
        } finally {
            $this->sshService->disconnect();
        }

        $this->bustCache($agent);
        try {
            broadcast(new WorkspaceUpdatedEvent($agent));
        } catch (\Throwable $e) {
            Log::warning('Failed to broadcast WorkspaceUpdatedEvent', ['agent_id' => $agent->id, 'error' => $e->getMessage()]);
        }

        return response()->json(['success' => true]);
    }

    public function destroy(Agent $agent, Request $request): JsonResponse
    {
        $this->authorizeAgent($agent, $request);

        $request->validate([
            'path' => ['required', 'string', 'max:500'],
        ]);

        $server = $agent->server;
        abort_unless($server, 404);

        $basePath = $this->basePath($agent);
        $filePath = $this->sanitizePath($request->input('path') ?? '');

        if (! $filePath) {
            return response()->json(['message' => 'Invalid path.'], 422);
        }

        // Block deletion of system files
        $rootSegment = explode('/', $filePath, 2)[0];
        if (str_starts_with($rootSegment, '.') || in_array($rootSegment, self::HIDDEN_SYSTEM_FILES)) {
            return response()->json(['message' => 'Cannot delete system files.'], 403);
        }

        $fullPath = "{$basePath}/{$filePath}";

        $this->sshService->connect($server);

        try {
            $this->sshService->exec('rm -rf '.escapeshellarg($fullPath));
        } finally {
            $this->sshService->disconnect();
        }

        $this->bustCache($agent);
        try {
            broadcast(new WorkspaceUpdatedEvent($agent));
        } catch (\Throwable $e) {
            Log::warning('Failed to broadcast WorkspaceUpdatedEvent', ['agent_id' => $agent->id, 'error' => $e->getMessage()]);
        }

        return response()->json(['success' => true]);
    }

    public function download(Agent $agent, Request $request): StreamedResponse|JsonResponse
    {
        $this->authorizeAgent($agent, $request);

        $server = $agent->server;
        abort_unless($server, 404);

        $basePath = $this->basePath($agent);
        $filePath = $this->sanitizePath($request->query('path') ?? '');

        if (! $filePath) {
            return response()->json(['message' => 'Invalid path.'], 422);
        }

        $fullPath = "{$basePath}/{$filePath}";

        $this->sshService->connect($server);

        try {
            $content = $this->sshService->readFile($fullPath);
        } catch (RuntimeException) {
            $this->sshService->disconnect();

            return response()->json(['message' => 'File not found.'], 404);
        } finally {
            $this->sshService->disconnect();
        }

        $filename = basename($filePath);

        return response()->streamDownload(function () use ($content) {
            echo $content;
        }, $filename);
    }

    /**
     * SSH into the server and fetch the file listing + usage.
     *
     * @return array{files: list<array>, usage: int}
     */
    private function fetchFromServer(Agent $agent): array
    {
        $basePath = $this->basePath($agent);

        $this->sshService->connect($agent->server);

        try {
            $this->sshService->exec('mkdir -p '.escapeshellarg($basePath));

            $output = $this->sshService->exec(
                'find '.escapeshellarg($basePath)." -mindepth 1 \\( -type f -o -type d \\) -printf '%y|%s|%T@|%P\\n' 2>/dev/null || true"
            );

            $files = [];
            foreach (array_filter(explode("\n", trim($output))) as $line) {
                $parts = explode('|', $line, 4);
                if (count($parts) < 4) {
                    continue;
                }

                [$type, $size, $mtime, $path] = $parts;

                // Hide dotfiles/dotdirs and OpenClaw system files at root level
                $rootSegment = explode('/', $path, 2)[0];
                if (str_starts_with($rootSegment, '.') || in_array($rootSegment, self::HIDDEN_SYSTEM_FILES)) {
                    continue;
                }

                $files[] = [
                    'name' => basename($path),
                    'path' => $path,
                    'type' => $type === 'd' ? 'directory' : 'file',
                    'size' => (int) $size,
                    'modified_at' => date('c', (int) (float) $mtime),
                ];
            }

            $usageOutput = trim($this->sshService->exec(
                'du -sb '.escapeshellarg($basePath).' 2>/dev/null | cut -f1 || echo 0'
            ));
            $usage = (int) $usageOutput;
        } catch (RuntimeException) {
            $files = [];
            $usage = 0;
        } finally {
            $this->sshService->disconnect();
        }

        return ['files' => $files, 'usage' => $usage];
    }

    private function cacheKey(Agent $agent): string
    {
        return "workspace:{$agent->id}";
    }

    private function bustCache(Agent $agent): void
    {
        Cache::forget($this->cacheKey($agent));
    }

    private function basePath(Agent $agent): string
    {
        return "/root/.openclaw/agents/{$agent->harness_agent_id}";
    }

    private function sanitizePath(string $path): string
    {
        // Remove null bytes
        $path = str_replace("\0", '', $path);

        // Normalize separators
        $path = str_replace('\\', '/', $path);

        // Remove leading slashes
        $path = ltrim($path, '/');

        // Remove .. traversal
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

    private function authorizeAgent(Agent $agent, Request $request): void
    {
        abort_unless($agent->team_id === $request->user()->currentTeam->id, 404);
    }
}
