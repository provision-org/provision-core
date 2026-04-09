<?php

namespace App\Http\Controllers;

use App\Services\SshService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SharedWorkspaceController extends Controller
{
    private const BASE_PATH = '/mnt/provision-shared';

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

    public function __construct(private SshService $sshService) {}

    public function index(Request $request): Response|JsonResponse
    {
        $team = $request->user()->currentTeam;
        $server = $team->server;

        if ($request->wantsJson()) {
            if (! $server) {
                return response()->json(['files' => [], 'usage' => 0, 'cached' => false]);
            }

            $fresh = $request->boolean('fresh');
            $cacheKey = $this->cacheKey($team->id);

            if (! $fresh) {
                $cached = Cache::get($cacheKey);
                if ($cached) {
                    return response()->json([...$cached, 'cached' => true]);
                }
            }

            $data = $this->fetchFromServer($server);

            Cache::put($cacheKey, $data, self::CACHE_TTL);

            return response()->json([...$data, 'cached' => false]);
        }

        return Inertia::render('company/workspace/index', [
            'hasServer' => (bool) $server,
        ]);
    }

    public function upload(Request $request): JsonResponse
    {
        $team = $request->user()->currentTeam;
        $server = $team->server;
        abort_unless($server, 404);

        $request->validate([
            'files' => ['required', 'array', 'max:'.self::MAX_FILES_PER_UPLOAD],
            'files.*' => ['required', 'file', 'max:'.(self::MAX_FILE_SIZE / 1024)],
            'path' => ['nullable', 'string', 'max:500'],
        ]);

        $subPath = $this->sanitizePath($request->input('path') ?? '');

        $this->sshService->connect($server);

        try {
            $targetDir = $subPath ? self::BASE_PATH."/{$subPath}" : self::BASE_PATH;
            $this->sshService->exec('mkdir -p '.escapeshellarg($targetDir));

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

        $this->bustCache($team->id);

        return response()->json([
            'uploaded' => $uploaded,
            'count' => count($uploaded),
        ]);
    }

    public function createFolder(Request $request): JsonResponse
    {
        $team = $request->user()->currentTeam;
        $server = $team->server;
        abort_unless($server, 404);

        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'path' => ['nullable', 'string', 'max:500'],
        ]);

        $subPath = $this->sanitizePath($request->input('path') ?? '');
        $folderName = $this->sanitizePath($request->input('name') ?? '');

        if (! $folderName) {
            return response()->json(['message' => 'Invalid folder name.'], 422);
        }

        $targetDir = $subPath ? self::BASE_PATH."/{$subPath}/{$folderName}" : self::BASE_PATH."/{$folderName}";

        $this->sshService->connect($server);

        try {
            $this->sshService->exec('mkdir -p '.escapeshellarg($targetDir));
        } finally {
            $this->sshService->disconnect();
        }

        $this->bustCache($team->id);

        return response()->json(['success' => true]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $team = $request->user()->currentTeam;
        $server = $team->server;
        abort_unless($server, 404);

        $request->validate([
            'path' => ['required', 'string', 'max:500'],
        ]);

        $filePath = $this->sanitizePath($request->input('path') ?? '');

        if (! $filePath) {
            return response()->json(['message' => 'Invalid path.'], 422);
        }

        $fullPath = self::BASE_PATH."/{$filePath}";

        $this->sshService->connect($server);

        try {
            $this->sshService->exec('rm -rf '.escapeshellarg($fullPath));
        } finally {
            $this->sshService->disconnect();
        }

        $this->bustCache($team->id);

        return response()->json(['success' => true]);
    }

    public function download(Request $request): StreamedResponse|JsonResponse
    {
        $team = $request->user()->currentTeam;
        $server = $team->server;
        abort_unless($server, 404);

        $filePath = $this->sanitizePath($request->query('path') ?? '');

        if (! $filePath) {
            return response()->json(['message' => 'Invalid path.'], 422);
        }

        $fullPath = self::BASE_PATH."/{$filePath}";

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
    private function fetchFromServer(mixed $server): array
    {
        $this->sshService->connect($server);

        try {
            $this->sshService->exec('mkdir -p '.escapeshellarg(self::BASE_PATH));

            $output = $this->sshService->exec(
                'find '.escapeshellarg(self::BASE_PATH)." -mindepth 1 \\( -type f -o -type d \\) -printf '%y|%s|%T@|%P\\n' 2>/dev/null || true"
            );

            $files = [];
            foreach (array_filter(explode("\n", trim($output))) as $line) {
                $parts = explode('|', $line, 4);
                if (count($parts) < 4) {
                    continue;
                }

                [$type, $size, $mtime, $path] = $parts;

                // Hide dotfiles/dotdirs at root level
                $rootSegment = explode('/', $path, 2)[0];
                if (str_starts_with($rootSegment, '.')) {
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
                'du -sb '.escapeshellarg(self::BASE_PATH).' 2>/dev/null | cut -f1 || echo 0'
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

    private function cacheKey(int|string $teamId): string
    {
        return "shared-workspace:{$teamId}";
    }

    private function bustCache(int|string $teamId): void
    {
        Cache::forget($this->cacheKey($teamId));
    }

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
