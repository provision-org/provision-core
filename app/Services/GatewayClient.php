<?php

namespace App\Services;

use App\Contracts\CommandExecutor;
use App\Enums\HarnessType;
use App\Models\Agent;
use App\Models\Server;
use Illuminate\Support\Facades\Log;

class GatewayClient
{
    private CommandExecutor $executor;

    private ?Agent $agent = null;

    public function __construct(
        private Server $server,
    ) {
        $this->executor = app(HarnessManager::class)->resolveExecutor($server);
    }

    /**
     * Set the agent context (needed for harness-specific API routing).
     */
    public function forAgent(Agent $agent): static
    {
        $this->agent = $agent;

        return $this;
    }

    /**
     * Send a message and wait for the full assistant response via the gateway HTTP API.
     *
     * @param  list<array{type: string, mimeType: string, fileName: string, path: string}>  $attachments
     * @return list<array{type: string, text?: string, path?: string, fileName?: string, mimeType?: string}>|null
     */
    public function chatSendAndWait(string $sessionKey, string $agentId, string $message, array $attachments = [], int $timeoutSeconds = 180): ?array
    {
        try {
            $response = $this->curlApi($agentId, $sessionKey, $message, false, $timeoutSeconds);

            if ($response === null) {
                return null;
            }

            $data = json_decode($response, true);
            $content = $data['choices'][0]['message']['content'] ?? null;

            if ($content === null) {
                return null;
            }

            return [['type' => 'text', 'text' => $content]];
        } catch (\Throwable $e) {
            Log::error('GatewayClient chatSendAndWait failed', [
                'server_id' => $this->server->id,
                'session_key' => $sessionKey,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Send a message and stream the assistant response via the gateway SSE API.
     *
     * Uses curl inside the agent container to hit the gateway's loopback API,
     * then parses the SSE output and yields token chunks.
     *
     * @param  list<array{type: string, mimeType: string, fileName: string, path: string}>  $attachments
     * @return \Generator<int, array{type: string, text?: string, content?: list<array<string, mixed>>, message?: string}>
     */
    public function chatSendAndStream(string $sessionKey, string $agentId, string $message, array $attachments = [], int $timeoutSeconds = 300): \Generator
    {
        try {
            $response = $this->curlApi($agentId, $sessionKey, $message, true, $timeoutSeconds);

            if ($response === null) {
                yield ['type' => 'error', 'message' => 'No response from the agent gateway.'];

                return;
            }

            // Parse SSE output: each line is "data: {...}" or "data: [DONE]"
            $fullText = '';
            $lines = explode("\n", $response);

            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || ! str_starts_with($line, 'data: ')) {
                    continue;
                }

                $data = substr($line, 6);

                if ($data === '[DONE]') {
                    yield [
                        'type' => 'done',
                        'content' => [['type' => 'text', 'text' => $fullText]],
                    ];

                    return;
                }

                $parsed = json_decode($data, true);
                if (! $parsed) {
                    continue;
                }

                $delta = $parsed['choices'][0]['delta'] ?? [];
                $tokenText = $delta['content'] ?? null;

                if ($tokenText !== null && $tokenText !== '') {
                    $fullText .= $tokenText;
                    yield ['type' => 'token', 'text' => $tokenText];
                }
            }

            // If we got content but no [DONE]
            if ($fullText !== '') {
                yield [
                    'type' => 'done',
                    'content' => [['type' => 'text', 'text' => $fullText]],
                ];
            } else {
                yield ['type' => 'error', 'message' => 'The agent did not respond.'];
            }
        } catch (\Throwable $e) {
            Log::error('GatewayClient chatSendAndStream failed', [
                'server_id' => $this->server->id,
                'session_key' => $sessionKey,
                'error' => $e->getMessage(),
            ]);

            yield ['type' => 'error', 'message' => 'Failed to communicate with the agent.'];
        }
    }

    /**
     * Check if the gateway is healthy.
     */
    public function health(): bool
    {
        try {
            $port = $this->isHermes() ? 8642 : 18789;
            $output = $this->executor->exec("curl -sf http://127.0.0.1:{$port}/health 2>/dev/null || echo FAIL");

            return ! str_contains($output, 'FAIL');
        } catch (\Throwable) {
            return false;
        }
    }

    // -------------------------------------------------------------------------
    // Core API call via executor (runs curl inside the agent container)
    // -------------------------------------------------------------------------

    /**
     * Execute a curl request to the gateway API from inside the agent container.
     *
     * This works for both Docker and SSH because the gateway binds to loopback
     * and we run curl on the same machine as the gateway.
     */
    private function curlApi(string $agentId, string $sessionKey, string $message, bool $stream, int $timeoutSeconds): ?string
    {
        $port = $this->isHermes() ? 8642 : 18789;
        $token = $this->resolveApiToken($agentId);
        $model = $this->resolveModel($agentId);

        $payload = json_encode([
            'model' => $model,
            'messages' => [['role' => 'user', 'content' => $message]],
            'stream' => $stream,
        ]);

        $headers = [
            '-H', 'Content-Type: application/json',
            '-H', "Authorization: Bearer {$token}",
        ];

        // Add harness-specific session headers
        if ($this->isHermes()) {
            $headers[] = '-H';
            $headers[] = "X-Hermes-Session-Id: {$sessionKey}";
        } else {
            $headers[] = '-H';
            $headers[] = "x-openclaw-agent-id: {$agentId}";
            $headers[] = '-H';
            $headers[] = "x-openclaw-session-key: {$sessionKey}";
        }

        $headerStr = implode(' ', array_map('escapeshellarg', $headers));
        $escapedPayload = escapeshellarg($payload);
        $streamFlag = $stream ? '-N' : '';

        $command = "curl -sS {$streamFlag} --max-time {$timeoutSeconds} {$headerStr} -d {$escapedPayload} http://127.0.0.1:{$port}/v1/chat/completions 2>&1";

        $output = $this->executor->exec($command);

        if (str_contains($output, 'curl:') || str_contains($output, 'Connection refused')) {
            Log::error('GatewayClient curl failed', [
                'server_id' => $this->server->id,
                'output' => substr($output, 0, 500),
            ]);

            return null;
        }

        return $output;
    }

    // -------------------------------------------------------------------------
    // Harness-specific helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve the API authentication token.
     */
    private function resolveApiToken(string $agentId): string
    {
        if ($this->isHermes()) {
            try {
                $output = $this->executor->exec(
                    'grep "^API_SERVER_KEY=" '.escapeshellarg("/root/.hermes-{$agentId}/.env").' 2>/dev/null || echo "API_SERVER_KEY="'
                );

                return trim(str_replace('API_SERVER_KEY=', '', $output)) ?: 'provision-local-dev';
            } catch (\Throwable) {
                return 'provision-local-dev';
            }
        }

        // OpenClaw: read token from openclaw.json
        try {
            $configContent = $this->executor->exec('cat /root/.openclaw/openclaw.json 2>/dev/null');
            $config = json_decode($configContent, true);

            return $config['gateway']['auth']['token'] ?? '';
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Resolve the model name for the API request.
     */
    private function resolveModel(string $agentId): string
    {
        if ($this->isHermes()) {
            return 'hermes-agent';
        }

        return "openclaw/{$agentId}";
    }

    private function isHermes(): bool
    {
        if (isset($this->agent)) {
            return $this->agent->harness_type === HarnessType::Hermes;
        }

        return $this->server->team?->harness_type === HarnessType::Hermes;
    }
}
