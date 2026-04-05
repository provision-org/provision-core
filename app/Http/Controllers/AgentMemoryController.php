<?php

namespace App\Http\Controllers;

use App\Enums\HarnessType;
use App\Models\Agent;
use App\Services\HarnessManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class AgentMemoryController extends Controller
{
    public function __construct(private HarnessManager $harnessManager) {}

    /**
     * List memory files for an agent.
     */
    public function index(Agent $agent, Request $request): JsonResponse
    {
        $this->authorizeAgent($agent, $request);

        $server = $agent->server;
        if (! $server) {
            return response()->json(['files' => [], 'index' => null]);
        }

        $executor = $this->harnessManager->resolveExecutor($server);
        $basePath = $this->memoryPath($agent);
        $indexPath = $this->indexPath($agent);

        try {
            // Check if MEMORY.md index exists
            $indexContent = null;
            try {
                $indexContent = trim($executor->exec('cat '.escapeshellarg($indexPath).' 2>/dev/null || true'));
                if ($indexContent === '') {
                    $indexContent = null;
                }
            } catch (RuntimeException) {
                // No index file yet
            }

            // List files in memory directory
            $output = $executor->exec(
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

            // Filter out MEMORY.md from the files list since it's shown separately as the index
            if ($indexContent !== null) {
                $files = array_values(array_filter($files, fn (array $f) => $f['name'] !== 'MEMORY.md'));
            }

            // Sort alphabetically
            usort($files, fn (array $a, array $b) => strcasecmp($a['name'], $b['name']));
        } catch (RuntimeException) {
            $files = [];
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

        $filePath = $filename === 'MEMORY.md'
            ? $this->indexPath($agent)
            : $this->memoryPath($agent).'/'.$filename;

        $executor = $this->harnessManager->resolveExecutor($server);

        try {
            $content = trim($executor->exec('cat '.escapeshellarg($filePath)));
        } catch (RuntimeException) {
            return response()->json(['message' => 'File not found.'], 404);
        }

        if ($content === '') {
            return response()->json(['message' => 'File not found.'], 404);
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

        $executor = $this->harnessManager->resolveExecutor($server);

        $encoded = base64_encode($request->input('content'));
        $executor->exec(
            'mkdir -p '.escapeshellarg(dirname($filePath))
            .' && echo '.escapeshellarg($encoded)
            .' | base64 -d > '.escapeshellarg($filePath)
        );

        return response()->json(['success' => true]);
    }

    private function memoryPath(Agent $agent): string
    {
        if ($agent->harness_type === HarnessType::Hermes) {
            return "/root/.hermes-{$agent->harness_agent_id}/memories";
        }

        return "/root/.openclaw/agents/{$agent->harness_agent_id}/memory";
    }

    private function indexPath(Agent $agent): string
    {
        if ($agent->harness_type === HarnessType::Hermes) {
            return "/root/.hermes-{$agent->harness_agent_id}/memories/MEMORY.md";
        }

        return "/root/.openclaw/agents/{$agent->harness_agent_id}/MEMORY.md";
    }

    /**
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

    private function sanitizeFilename(string $filename): string
    {
        $filename = str_replace("\0", '', $filename);
        $filename = basename($filename);

        if ($filename !== 'MEMORY.md' && str_starts_with($filename, '.')) {
            return '';
        }

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
