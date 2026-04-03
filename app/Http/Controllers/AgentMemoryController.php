<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Services\SshService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class AgentMemoryController extends Controller
{
    public function __construct(private SshService $sshService) {}

    /**
     * List memory files for an agent.
     *
     * Reads ~/.openclaw/memory/ directory and the MEMORY.md index file.
     */
    public function index(Agent $agent, Request $request): JsonResponse
    {
        $this->authorizeAgent($agent, $request);

        $server = $agent->server;
        if (! $server) {
            return response()->json(['files' => [], 'index' => null]);
        }

        $basePath = $this->memoryPath($agent);
        $indexPath = $this->indexPath($agent);

        $this->sshService->connect($server);

        try {
            // Check if MEMORY.md index exists
            $indexContent = null;
            try {
                $indexContent = $this->sshService->readFile($indexPath);
            } catch (RuntimeException) {
                // No index file yet
            }

            // List files in memory directory
            $output = $this->sshService->exec(
                'find '.escapeshellarg($basePath)." -maxdepth 1 -type f -printf '%s|%T@|%f\\n' 2>/dev/null || true"
            );

            $files = [];
            foreach (array_filter(explode("\n", trim($output))) as $line) {
                $parts = explode('|', $line, 3);
                if (count($parts) < 3) {
                    continue;
                }

                [$size, $mtime, $name] = $parts;

                $files[] = [
                    'name' => $name,
                    'size' => (int) $size,
                    'modified_at' => date('c', (int) (float) $mtime),
                ];
            }

            // Sort alphabetically
            usort($files, fn (array $a, array $b) => strcasecmp($a['name'], $b['name']));
        } catch (RuntimeException) {
            $files = [];
        } finally {
            $this->sshService->disconnect();
        }

        return response()->json([
            'files' => $files,
            'index' => $indexContent,
        ]);
    }

    /**
     * Read a single memory file's content.
     */
    public function show(Agent $agent, string $filename, Request $request): JsonResponse
    {
        $this->authorizeAgent($agent, $request);

        $server = $agent->server;
        abort_unless($server, 404);

        $filename = $this->sanitizeFilename($filename);
        abort_unless($filename, 422, 'Invalid filename.');

        // Determine the path: MEMORY.md lives in parent dir, others in memory/
        $filePath = $filename === 'MEMORY.md'
            ? $this->indexPath($agent)
            : $this->memoryPath($agent).'/'.$filename;

        $this->sshService->connect($server);

        try {
            $content = $this->sshService->readFile($filePath);
        } catch (RuntimeException) {
            $this->sshService->disconnect();

            return response()->json(['message' => 'File not found.'], 404);
        } finally {
            $this->sshService->disconnect();
        }

        $frontmatter = $this->parseFrontmatter($content);

        return response()->json([
            'filename' => $filename,
            'content' => $content,
            'frontmatter' => $frontmatter,
        ]);
    }

    /**
     * Update a memory file's content.
     */
    public function update(Agent $agent, string $filename, Request $request): JsonResponse
    {
        $this->authorizeAgent($agent, $request);

        $request->validate([
            'content' => ['required', 'string'],
        ]);

        $server = $agent->server;
        abort_unless($server, 404);

        $filename = $this->sanitizeFilename($filename);
        abort_unless($filename, 422, 'Invalid filename.');

        $filePath = $filename === 'MEMORY.md'
            ? $this->indexPath($agent)
            : $this->memoryPath($agent).'/'.$filename;

        $this->sshService->connect($server);

        try {
            $encoded = base64_encode($request->input('content'));
            $this->sshService->exec(
                'mkdir -p '.escapeshellarg(dirname($filePath))
                .' && echo '.escapeshellarg($encoded)
                .' | base64 -d > '.escapeshellarg($filePath)
            );
        } finally {
            $this->sshService->disconnect();
        }

        return response()->json(['success' => true]);
    }

    /**
     * Base path for the agent's memory directory.
     */
    private function memoryPath(Agent $agent): string
    {
        return "/root/.openclaw/agents/{$agent->harness_agent_id}/memory";
    }

    /**
     * Path to the MEMORY.md index file (in parent directory).
     */
    private function indexPath(Agent $agent): string
    {
        return "/root/.openclaw/agents/{$agent->harness_agent_id}/MEMORY.md";
    }

    /**
     * Parse YAML frontmatter from markdown content.
     *
     * @return array{name?: string, description?: string, type?: string}|null
     */
    private function parseFrontmatter(string $content): ?array
    {
        if (! str_starts_with(trim($content), '---')) {
            return null;
        }

        $parts = preg_split('/^---\s*$/m', $content, 3);
        if (! $parts || count($parts) < 3) {
            return null;
        }

        $yaml = trim($parts[1]);
        $frontmatter = [];

        foreach (explode("\n", $yaml) as $line) {
            if (str_contains($line, ':')) {
                [$key, $value] = explode(':', $line, 2);
                $key = trim($key);
                $value = trim($value, " \t\n\r\0\x0B\"'");
                if ($key && $value !== '') {
                    $frontmatter[$key] = $value;
                }
            }
        }

        return $frontmatter ?: null;
    }

    /**
     * Sanitize a filename to prevent path traversal.
     */
    private function sanitizeFilename(string $filename): string
    {
        // Remove null bytes
        $filename = str_replace("\0", '', $filename);

        // Only allow the basename (no slashes)
        $filename = basename($filename);

        // Block hidden files (except MEMORY.md is always allowed)
        if ($filename !== 'MEMORY.md' && str_starts_with($filename, '.')) {
            return '';
        }

        // Block directory traversal
        if ($filename === '.' || $filename === '..') {
            return '';
        }

        return $filename;
    }

    private function authorizeAgent(Agent $agent, Request $request): void
    {
        abort_unless($agent->team_id === $request->user()->currentTeam->id, 404);
    }
}
