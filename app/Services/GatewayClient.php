<?php

namespace App\Services;

use App\Contracts\CommandExecutor;
use App\Models\Agent;
use App\Models\Server;
use Illuminate\Support\Facades\Log;

/**
 * Hermes-only chat transport (per-agent OpenAI-compatible API server).
 *
 * The former OpenClaw arm (curl to the gateway's /v1/responses on 18789 with
 * the token scraped from openclaw.json) was retired: OpenClaw chat goes
 * through OpenClawChatService's gateway RPC (`chat.send` with idempotency
 * keys) — the documented transport — and both call sites gate on
 * HarnessType before reaching this class.
 */
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
            $text = $this->extractTextFromResponsesOutput($data);

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

            $fullText = '';
            $lines = explode("\n", $response);

            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || ! str_starts_with($line, 'data: ')) {
                    continue;
                }

                $data = substr($line, 6);

                if ($data === '[DONE]') {
                    yield ['type' => 'done', 'content' => [['type' => 'text', 'text' => $fullText]]];

                    return;
                }

                $parsed = json_decode($data, true);
                if (! $parsed) {
                    continue;
                }

                $tokenText = $this->extractStreamingToken($parsed);

                if ($tokenText !== null && $tokenText !== '') {
                    $fullText .= $tokenText;
                    yield ['type' => 'token', 'text' => $tokenText];
                }
            }

            if ($fullText !== '') {
                yield ['type' => 'done', 'content' => [['type' => 'text', 'text' => $fullText]]];
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
            $port = $this->resolveApiPort();
            $output = $this->executor->exec("curl -sf http://127.0.0.1:{$port}/health 2>/dev/null || echo FAIL");

            return ! str_contains($output, 'FAIL');
        } catch (\Throwable) {
            return false;
        }
    }

    // -------------------------------------------------------------------------
    // API call via executor
    // -------------------------------------------------------------------------

    private function callResponsesApi(string $agentId, string $sessionKey, string $message, bool $stream, int $timeoutSeconds): ?string
    {
        $port = $this->resolveApiPort();
        $token = $this->resolveApiToken($agentId);

        $payload = [
            'model' => $this->resolveModel($agentId),
            'input' => $message,
            'stream' => $stream,
        ];

        // Session continuity (Hermes conversation threading)
        $payload['conversation'] = $sessionKey;

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

    private function extractTextFromResponsesOutput(array $data): ?string
    {
        foreach ($data['output'] ?? [] as $item) {
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

    private function extractStreamingToken(array $parsed): ?string
    {
        // Chat completions format
        if (isset($parsed['choices'][0]['delta']['content'])) {
            return $parsed['choices'][0]['delta']['content'];
        }

        // Responses streaming format
        if (($parsed['type'] ?? '') === 'response.output_text.delta') {
            return $parsed['delta'] ?? null;
        }

        if (($parsed['type'] ?? '') === 'response.content_part.delta') {
            return $parsed['delta']['text'] ?? null;
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Harness-specific resolution
    // -------------------------------------------------------------------------

    /**
     * Resolve the per-agent Hermes API server port (each agent runs its own).
     */
    private function resolveApiPort(): int
    {
        return $this->agent?->api_server_port ?: 8642;
    }

    private function resolveApiToken(string $agentId): string
    {
        try {
            $output = $this->executor->exec(
                'grep "^API_SERVER_KEY=" '.escapeshellarg("/root/.hermes-{$agentId}/.env").' 2>/dev/null || echo "API_SERVER_KEY="'
            );

            return trim(str_replace('API_SERVER_KEY=', '', $output)) ?: 'provision-local-dev';
        } catch (\Throwable) {
            return 'provision-local-dev';
        }
    }

    private function resolveModel(string $agentId): string
    {
        return 'hermes-agent';
    }
}
