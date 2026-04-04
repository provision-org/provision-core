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
     * Send a message and wait for the full assistant response via the gateway Responses API.
     *
     * @param  list<array{type: string, mimeType: string, fileName: string, path: string}>  $attachments
     * @return list<array{type: string, text?: string, path?: string, fileName?: string, mimeType?: string}>|null
     */
    public function chatSendAndWait(string $sessionKey, string $agentId, string $message, array $attachments = [], int $timeoutSeconds = 180): ?array
    {
        try {
            $response = $this->callResponsesApi($agentId, $sessionKey, $message, false, $timeoutSeconds);

            if ($response === null) {
                return null;
            }

            $data = json_decode($response, true);

            // Extract text from the Responses API output format
            $text = $this->extractTextFromResponsesOutput($data);

            if ($text === null) {
                // Fallback: try chat completions format
                $text = $data['choices'][0]['message']['content'] ?? null;
            }

            if ($text === null) {
                return null;
            }

            return [['type' => 'text', 'text' => $text]];
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
     * @param  list<array{type: string, mimeType: string, fileName: string, path: string}>  $attachments
     * @return \Generator<int, array{type: string, text?: string, content?: list<array<string, mixed>>, message?: string}>
     */
    public function chatSendAndStream(string $sessionKey, string $agentId, string $message, array $attachments = [], int $timeoutSeconds = 300): \Generator
    {
        try {
            $response = $this->callResponsesApi($agentId, $sessionKey, $message, true, $timeoutSeconds);

            if ($response === null) {
                yield ['type' => 'error', 'message' => 'No response from the agent gateway.'];

                return;
            }

            // Parse SSE output
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

                // Handle both chat completions and responses streaming formats
                $tokenText = $this->extractStreamingToken($parsed);

                if ($tokenText !== null && $tokenText !== '') {
                    $fullText .= $tokenText;
                    yield ['type' => 'token', 'text' => $tokenText];
                }
            }

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
    // API call via executor (runs curl inside the agent container)
    // -------------------------------------------------------------------------

    /**
     * Call the Responses API (/v1/responses) on the gateway.
     *
     * Uses curl inside the agent container to hit loopback, works for both
     * Docker (docker exec) and SSH modes.
     */
    private function callResponsesApi(string $agentId, string $sessionKey, string $message, bool $stream, int $timeoutSeconds): ?string
    {
        $port = $this->isHermes() ? 8642 : 18789;
        $token = $this->resolveApiToken($agentId);

        $payload = [
            'model' => $this->resolveModel($agentId),
            'input' => $message,
            'stream' => $stream,
        ];

        // Session continuity
        if ($this->isHermes()) {
            // Hermes: use named conversations for automatic session chaining
            $payload['conversation'] = $sessionKey;
        } else {
            // OpenClaw: use 'user' field to derive stable session key
            $payload['user'] = $sessionKey;
        }

        $payloadJson = json_encode($payload);

        $headers = implode(' ', array_map('escapeshellarg', [
            '-H', 'Content-Type: application/json',
            '-H', "Authorization: Bearer {$token}",
        ]));

        $escapedPayload = escapeshellarg($payloadJson);
        $streamFlag = $stream ? '-N' : '';

        $command = "curl -sS {$streamFlag} --max-time {$timeoutSeconds} {$headers} -d {$escapedPayload} http://127.0.0.1:{$port}/v1/responses 2>&1";

        $output = $this->executor->exec($command);

        if (str_contains($output, 'curl:') || str_contains($output, 'Connection refused')) {
            Log::error('GatewayClient API call failed', [
                'server_id' => $this->server->id,
                'output' => substr($output, 0, 500),
            ]);

            return null;
        }

        return $output;
    }

    // -------------------------------------------------------------------------
    // Response parsing
    // -------------------------------------------------------------------------

    /**
     * Extract text from the Responses API output format.
     *
     * OpenResponses returns: {output: [{type: "message", content: [{type: "output_text", text: "..."}]}]}
     */
    private function extractTextFromResponsesOutput(array $data): ?string
    {
        $output = $data['output'] ?? [];

        foreach ($output as $item) {
            if (($item['type'] ?? '') !== 'message') {
                continue;
            }

            foreach ($item['content'] ?? [] as $block) {
                if (($block['type'] ?? '') === 'output_text' && ! empty($block['text'])) {
                    return $block['text'];
                }
            }
        }

        return null;
    }

    /**
     * Extract a streaming token from an SSE chunk.
     *
     * Supports both chat completions format (delta.content) and
     * responses streaming format (delta.text / content_part).
     */
    private function extractStreamingToken(array $parsed): ?string
    {
        // Chat completions format: choices[0].delta.content
        $delta = $parsed['choices'][0]['delta'] ?? null;
        if ($delta && isset($delta['content'])) {
            return $delta['content'];
        }

        // Responses streaming format: type=response.output_text.delta
        if (($parsed['type'] ?? '') === 'response.output_text.delta') {
            return $parsed['delta'] ?? null;
        }

        // Responses content part
        if (($parsed['type'] ?? '') === 'response.content_part.delta') {
            return $parsed['delta']['text'] ?? null;
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Harness-specific helpers
    // -------------------------------------------------------------------------

    private function resolveApiToken(string $agentId): string
    {
        if ($this->isHermes()) {
            // All Hermes agents on a server share one API server.
            // Find the API_SERVER_KEY from any agent's .env (the gateway uses whichever started first).
            try {
                $output = $this->executor->exec(
                    'grep -rh "^API_SERVER_KEY=" /root/.hermes-*/.env 2>/dev/null | head -1 || echo "API_SERVER_KEY="'
                );

                return trim(str_replace('API_SERVER_KEY=', '', $output)) ?: 'provision-local-dev';
            } catch (\Throwable) {
                return 'provision-local-dev';
            }
        }

        try {
            $configContent = $this->executor->exec('cat /root/.openclaw/openclaw.json 2>/dev/null');
            $config = json_decode($configContent, true);

            return $config['gateway']['auth']['token'] ?? '';
        } catch (\Throwable) {
            return '';
        }
    }

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
