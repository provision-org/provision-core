<?php

namespace App\Services;

use App\Models\Server;
use Illuminate\Support\Facades\Log;

class GatewayClient
{
    private ?string $host = null;

    private ?string $gatewayToken = null;

    private SshService $sshService;

    public function __construct(private Server $server)
    {
        $this->sshService = new SshService;
        $this->host = $server->ipv4_address;
        $this->gatewayToken = $server->gatewayConfig?->gateway_token;
    }

    /**
     * Send a message and wait for the assistant response via SSH CLI fallback.
     *
     * Uses `openclaw agent --message --deliver` + polls session for response.
     *
     * @param  list<array{type: string, mimeType: string, fileName: string, path: string}>  $attachments
     * @return list<array{type: string, text?: string, path?: string, fileName?: string, mimeType?: string}>|null
     */
    public function chatSendAndWait(string $sessionKey, string $agentId, string $message, array $attachments = [], int $timeoutSeconds = 180): ?array
    {
        $this->sshService->connect($this->server);

        try {
            // Count existing assistant messages so we can detect a NEW response
            $initialAssistantCount = $this->countAssistantMessages($agentId);

            $escapedMessage = str_replace('"', '\\"', $message);
            $command = "openclaw agent --agent {$agentId} --message \"{$escapedMessage}\" --deliver";

            $this->sshService->exec($command);

            // Poll for response by reading the latest session JSONL
            return $this->pollForResponse($agentId, $timeoutSeconds, $initialAssistantCount);
        } catch (\Throwable $e) {
            Log::error('GatewayClient chatSendAndWait failed', [
                'server_id' => $this->server->id,
                'session_key' => $sessionKey,
                'error' => $e->getMessage(),
            ]);

            return null;
        } finally {
            $this->sshService->disconnect();
        }
    }

    /**
     * Send a message and stream the assistant response via SSH tail.
     *
     * Yields partial text chunks as they arrive from the session JSONL file.
     * Each yielded value is an associative array with 'type' and optional 'text' keys:
     *   - ['type' => 'token', 'text' => '...']    partial content chunk
     *   - ['type' => 'done',  'content' => [...]]  final content blocks
     *   - ['type' => 'error', 'message' => '...']  error occurred
     *
     * @param  list<array{type: string, mimeType: string, fileName: string, path: string}>  $attachments
     * @return \Generator<int, array{type: string, text?: string, content?: list<array<string, mixed>>, message?: string}>
     */
    public function chatSendAndStream(string $sessionKey, string $agentId, string $message, array $attachments = [], int $timeoutSeconds = 300): \Generator
    {
        $this->sshService->connect($this->server);

        try {
            $initialAssistantCount = $this->countAssistantMessages($agentId);

            $escapedMessage = str_replace('"', '\\"', $message);
            $command = "openclaw agent --agent {$agentId} --message \"{$escapedMessage}\" --deliver";

            $this->sshService->exec($command);

            // Poll the JSONL file for streaming chunks
            yield from $this->pollForStreamingResponse($agentId, $timeoutSeconds, $initialAssistantCount);
        } catch (\Throwable $e) {
            Log::error('GatewayClient chatSendAndStream failed', [
                'server_id' => $this->server->id,
                'session_key' => $sessionKey,
                'error' => $e->getMessage(),
            ]);

            yield ['type' => 'error', 'message' => 'Failed to communicate with the agent.'];
        } finally {
            $this->sshService->disconnect();
        }
    }

    /**
     * Fetch chat history for a session from the server.
     *
     * @return list<array{role: string, content: list<array<string, mixed>>, timestamp: string|null}>
     */
    public function chatHistory(string $agentId, string $sessionKey): array
    {
        $this->sshService->connect($this->server);

        try {
            $sessionsPath = "/mnt/openclaw-data/agents/{$agentId}/sessions/sessions.json";
            $sessionsContent = $this->sshService->readFile($sessionsPath);
            $sessions = json_decode($sessionsContent, true) ?? [];

            // Find the session matching our key
            $sessionData = null;
            foreach ($sessions as $session) {
                if (($session['sessionKey'] ?? null) === $sessionKey || ($session['key'] ?? null) === $sessionKey) {
                    $sessionData = $session;
                    break;
                }
            }

            if (! $sessionData || empty($sessionData['sessionFile'])) {
                return [];
            }

            return $this->parseSessionFile($sessionData['sessionFile']);
        } catch (\Throwable $e) {
            Log::error('GatewayClient chatHistory failed', [
                'server_id' => $this->server->id,
                'session_key' => $sessionKey,
                'error' => $e->getMessage(),
            ]);

            return [];
        } finally {
            $this->sshService->disconnect();
        }
    }

    /**
     * Check if the gateway is healthy.
     */
    public function health(): bool
    {
        try {
            $this->sshService->connect($this->server);
            $output = $this->sshService->exec('openclaw health');
            $this->sshService->disconnect();

            return str_contains($output, 'ok') || str_contains($output, 'healthy');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Poll session JSONL for streaming chunks, yielding partial text as it arrives.
     *
     * @return \Generator<int, array{type: string, text?: string, content?: list<array<string, mixed>>, message?: string}>
     */
    private function pollForStreamingResponse(string $agentId, int $timeoutSeconds, int $initialAssistantCount = 0): \Generator
    {
        $startTime = time();
        $pollInterval = 1;
        $lastContentLength = 0;
        $previousLineCount = 0;

        while ((time() - $startTime) < $timeoutSeconds) {
            usleep($pollInterval * 500_000); // Start at 0.5s, increase over time

            try {
                $sessionsPath = "/mnt/openclaw-data/agents/{$agentId}/sessions/sessions.json";
                $sessionsContent = $this->sshService->readFile($sessionsPath);
                $sessions = json_decode($sessionsContent, true) ?? [];

                $latestSession = null;
                $latestTime = '';
                foreach ($sessions as $session) {
                    $updatedAt = $session['updatedAt'] ?? '';
                    if ($updatedAt > $latestTime && ! empty($session['sessionFile'])) {
                        $latestTime = $updatedAt;
                        $latestSession = $session;
                    }
                }

                if (! $latestSession) {
                    continue;
                }

                $content = $this->sshService->readFile($latestSession['sessionFile']);
                $lines = array_filter(explode("\n", trim($content)));
                $lineCount = count($lines);

                // Only process new lines
                if ($lineCount <= $previousLineCount) {
                    $pollInterval = min($pollInterval + 1, 4);

                    continue;
                }

                // Reset poll interval when new data arrives
                $pollInterval = 1;
                $newLines = array_slice($lines, $previousLineCount);
                $previousLineCount = $lineCount;

                foreach ($newLines as $line) {
                    $entry = json_decode($line, true);
                    if (! $entry) {
                        continue;
                    }

                    $msg = $entry['message'] ?? $entry;
                    $role = $msg['role'] ?? null;

                    if ($role !== 'assistant') {
                        continue;
                    }

                    $rawContent = $msg['content'] ?? '';
                    $contentBlocks = $this->normalizeContentBlocks($rawContent);
                    $textContent = $this->extractTextFromBlocks($contentBlocks);

                    if (strlen($textContent) > $lastContentLength) {
                        $newText = substr($textContent, $lastContentLength);
                        $lastContentLength = strlen($textContent);

                        yield ['type' => 'token', 'text' => $newText];
                    }
                }

                // Check if the response is complete — new assistant message with text
                $messages = $this->parseSessionFile($latestSession['sessionFile']);
                $assistantWithText = array_filter(
                    $messages,
                    fn ($msg) => $msg['role'] === 'assistant' && $this->hasTextContent($msg['content']),
                );

                if (count($assistantWithText) > $initialAssistantCount) {
                    $finalMessage = end($assistantWithText);

                    // Check if the entry type indicates completion
                    $lastLine = end($lines);
                    $lastEntry = json_decode($lastLine, true);
                    $entryType = $lastEntry['type'] ?? null;

                    // Consider done if we see a result/complete entry or if content stopped growing
                    if ($entryType === 'result' || $entryType === 'complete') {
                        yield ['type' => 'done', 'content' => $finalMessage['content']];

                        return;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('GatewayClient stream poll error', ['error' => $e->getMessage()]);
            }
        }

        // If we timed out but have content, send what we have
        if ($lastContentLength > 0) {
            yield ['type' => 'done', 'content' => [['type' => 'text', 'text' => '']]];

            return;
        }

        yield ['type' => 'error', 'message' => 'The agent did not respond in time.'];
    }

    /**
     * Poll session JSONL for the assistant response after sending a message.
     *
     * @return list<array{type: string, text?: string, path?: string, fileName?: string, mimeType?: string}>|null
     */
    private function pollForResponse(string $agentId, int $timeoutSeconds, int $initialAssistantCount = 0): ?array
    {
        $startTime = time();
        $pollInterval = 3;

        while ((time() - $startTime) < $timeoutSeconds) {
            sleep($pollInterval);

            try {
                // Read sessions.json and find the most recently updated session
                $sessionsPath = "/mnt/openclaw-data/agents/{$agentId}/sessions/sessions.json";
                $sessionsContent = $this->sshService->readFile($sessionsPath);
                $sessions = json_decode($sessionsContent, true) ?? [];

                // Find the session with the latest updatedAt
                $latestSession = null;
                $latestTime = '';
                foreach ($sessions as $session) {
                    $updatedAt = $session['updatedAt'] ?? '';
                    if ($updatedAt > $latestTime && ! empty($session['sessionFile'])) {
                        $latestTime = $updatedAt;
                        $latestSession = $session;
                    }
                }

                if (! $latestSession) {
                    continue;
                }

                $messages = $this->parseSessionFile($latestSession['sessionFile']);

                // Count assistant messages with text — only return when we have MORE than before
                $assistantWithText = [];
                foreach ($messages as $msg) {
                    if ($msg['role'] === 'assistant' && $this->hasTextContent($msg['content'])) {
                        $assistantWithText[] = $msg;
                    }
                }

                if (count($assistantWithText) > $initialAssistantCount) {
                    return end($assistantWithText)['content'];
                }
            } catch (\Throwable $e) {
                Log::warning('GatewayClient poll error', ['error' => $e->getMessage()]);
            }

            // Increase poll interval over time
            $pollInterval = min($pollInterval + 2, 10);
        }

        return null;
    }

    /**
     * Parse a session JSONL file into structured messages.
     *
     * @return list<array{role: string, content: list<array<string, mixed>>, timestamp: string|null}>
     */
    private function parseSessionFile(string $filePath): array
    {
        $content = $this->sshService->readFile($filePath);
        $lines = array_filter(explode("\n", trim($content)));

        $messages = [];
        foreach ($lines as $line) {
            $entry = json_decode($line, true);
            if (! $entry || ($entry['type'] ?? null) !== 'message') {
                continue;
            }

            $msg = $entry['message'] ?? $entry;
            $role = $msg['role'] ?? 'unknown';

            if ($role !== 'user' && $role !== 'assistant') {
                continue;
            }

            $rawContent = $msg['content'] ?? '';
            $contentBlocks = $this->normalizeContentBlocks($rawContent);

            $messages[] = [
                'role' => $role,
                'content' => $contentBlocks,
                'timestamp' => $entry['timestamp'] ?? $msg['timestamp'] ?? null,
            ];
        }

        return $messages;
    }

    /**
     * Normalize raw content into structured content blocks.
     *
     * @return list<array<string, mixed>>
     */
    private function normalizeContentBlocks(mixed $rawContent): array
    {
        if (is_string($rawContent)) {
            return [['type' => 'text', 'text' => $rawContent]];
        }

        if (is_array($rawContent)) {
            return collect($rawContent)
                ->map(function ($block) {
                    if (is_string($block)) {
                        return ['type' => 'text', 'text' => $block];
                    }

                    if (is_array($block) && isset($block['type'])) {
                        return $block;
                    }

                    return null;
                })
                ->filter()
                ->values()
                ->all();
        }

        return [];
    }

    /**
     * Count assistant messages with text content in the latest session.
     */
    private function countAssistantMessages(string $agentId): int
    {
        try {
            $sessionsPath = "/mnt/openclaw-data/agents/{$agentId}/sessions/sessions.json";
            $sessionsContent = $this->sshService->readFile($sessionsPath);
            $sessions = json_decode($sessionsContent, true) ?? [];

            $latestSession = null;
            $latestTime = '';
            foreach ($sessions as $session) {
                $updatedAt = $session['updatedAt'] ?? '';
                if ($updatedAt > $latestTime && ! empty($session['sessionFile'])) {
                    $latestTime = $updatedAt;
                    $latestSession = $session;
                }
            }

            if (! $latestSession) {
                return 0;
            }

            $messages = $this->parseSessionFile($latestSession['sessionFile']);

            return count(array_filter($messages, fn ($msg) => $msg['role'] === 'assistant' && $this->hasTextContent($msg['content'])));
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Extract concatenated text from content blocks.
     *
     * @param  list<array<string, mixed>>  $blocks
     */
    private function extractTextFromBlocks(array $blocks): string
    {
        $text = '';
        foreach ($blocks as $block) {
            if (($block['type'] ?? null) === 'text' && ! empty($block['text'])) {
                $text .= $block['text'];
            }
        }

        return $text;
    }

    /**
     * Check if content blocks contain at least one text block.
     *
     * @param  list<array<string, mixed>>  $content
     */
    private function hasTextContent(array $content): bool
    {
        foreach ($content as $block) {
            if (($block['type'] ?? null) === 'text' && ! empty($block['text'])) {
                return true;
            }
        }

        return false;
    }
}
