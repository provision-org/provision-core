<?php

namespace App\Jobs;

use App\Enums\AgentStatus;
use App\Enums\ChatMessageRole;
use App\Events\ChatMessageErrorEvent;
use App\Events\ChatMessageReceivedEvent;
use App\Events\ChatMessageSendingEvent;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Services\GatewayClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SendAgentChatMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 240;

    public function __construct(
        public ChatConversation $conversation,
        public ChatMessage $userMessage,
    ) {}

    public function handle(): void
    {
        $this->conversation->loadMissing(['agent.server', 'agent.team']);

        $agent = $this->conversation->agent;

        if ($agent->status !== AgentStatus::Active || ! $agent->server) {
            Log::info("Skipping chat message — agent {$agent->id} not active or no server");
            $this->broadcastError('Agent is not available.');

            return;
        }

        $teamId = $agent->team_id;

        // Broadcast thinking indicator
        event(new ChatMessageSendingEvent($teamId, $this->conversation->id, $agent->id));

        $textContent = $this->userMessage->textContent();

        // Build attachments from user message content blocks
        $attachments = $this->buildAttachments();

        try {
            $client = new GatewayClient($agent->server);

            $responseBlocks = $client->chatSendAndWait(
                $this->conversation->session_key,
                $agent->harness_agent_id,
                $textContent,
                $attachments,
            );

            if (! $responseBlocks) {
                $this->broadcastError();

                return;
            }

            // Process response content blocks — save base64 images to storage
            $processedBlocks = $this->processResponseBlocks($responseBlocks);

            if (empty($processedBlocks)) {
                $this->broadcastError('The agent did not return a readable response.');

                return;
            }

            // Create assistant message
            $assistantMessage = $this->conversation->messages()->create([
                'role' => ChatMessageRole::Assistant,
                'content' => $processedBlocks,
                'sent_at' => now(),
            ]);

            $this->conversation->update(['last_message_at' => now()]);

            event(new ChatMessageReceivedEvent($assistantMessage, $teamId));
        } catch (\Throwable $e) {
            Log::error('SendAgentChatMessageJob failed', [
                'conversation_id' => $this->conversation->id,
                'error' => $e->getMessage(),
            ]);

            $this->broadcastError();
        }
    }

    /**
     * Build attachment data from user message content blocks.
     *
     * @return list<array{type: string, mimeType: string, fileName: string, path: string}>
     */
    private function buildAttachments(): array
    {
        $attachments = [];

        foreach ($this->userMessage->content as $block) {
            if (($block['type'] ?? null) === 'text') {
                continue;
            }

            if (! empty($block['path']) && Storage::exists($block['path'])) {
                $attachments[] = [
                    'type' => $block['type'],
                    'mimeType' => $block['mimeType'] ?? 'application/octet-stream',
                    'fileName' => $block['fileName'] ?? basename($block['path']),
                    'path' => $block['path'],
                ];
            }
        }

        return $attachments;
    }

    /**
     * Process response content blocks — decode base64 images and save to storage.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return list<array<string, mixed>>
     */
    private function processResponseBlocks(array $blocks): array
    {
        $processed = [];

        foreach ($blocks as $block) {
            if (($block['type'] ?? null) === 'text') {
                // Strip OpenClaw channel routing prefixes (e.g. [[reply_to_current]])
                $text = preg_replace('/^\[\[reply_to_\w+\]\]\s*/', '', $block['text'] ?? '');
                $processed[] = ['type' => 'text', 'text' => $text];

                continue;
            }

            // Handle image blocks with base64 content
            if (($block['type'] ?? null) === 'image' && ! empty($block['content'])) {
                $fileName = $block['fileName'] ?? 'image_'.uniqid().'.png';
                $path = "chat-attachments/{$this->conversation->id}/{$fileName}";

                Storage::put($path, base64_decode($block['content']));

                $processed[] = [
                    'type' => 'image',
                    'path' => $path,
                    'fileName' => $fileName,
                    'mimeType' => $block['mimeType'] ?? 'image/png',
                ];

                continue;
            }

            // Skip non-standard blocks (thinking, tool_use, etc.)
            if (in_array($block['type'] ?? null, ['image', 'file'])) {
                $processed[] = $block;
            }
        }

        return $processed;
    }

    private function broadcastError(string $message = 'The agent did not respond in time.'): void
    {
        $this->conversation->loadMissing('agent');

        event(new ChatMessageErrorEvent(
            $this->conversation->agent->team_id,
            $this->conversation->id,
            $message,
        ));
    }
}
