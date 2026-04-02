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
