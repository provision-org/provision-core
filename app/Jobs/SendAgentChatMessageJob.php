<?php

namespace App\Jobs;

use App\Enums\AgentStatus;
use App\Enums\ChatMessageRole;
use App\Enums\HarnessType;
use App\Events\ChatMessageErrorEvent;
use App\Events\ChatMessageReceivedEvent;
use App\Events\ChatMessageSendingEvent;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Services\GatewayClient;
use App\Services\OpenClawChatService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class SendAgentChatMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 260;

    public function __construct(
        public ChatConversation $conversation,
        public ChatMessage $userMessage,
    ) {
        $this->onQueue('chat');
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [5, 15];
    }

    public function handle(OpenClawChatService $openClawChat): void
    {
        $this->conversation->loadMissing(['agent.server', 'agent.team']);

        $agent = $this->conversation->agent;

        if ($agent->status !== AgentStatus::Active || ! $agent->server) {
            Log::info("Skipping chat message — agent {$agent->id} not active or no server");
            $this->markFailed('Agent is not available.');

            return;
        }

        $this->userMessage->refresh();
        if (in_array($this->userMessage->delivery_status, ['completed', 'aborted'], true)) {
            return;
        }

        $claimableStatuses = $this->attempts() > 1 ? ['queued', 'running'] : ['queued'];
        $claimed = ChatMessage::query()
            ->whereKey($this->userMessage->getKey())
            ->whereIn('delivery_status', $claimableStatuses)
            ->update([
                'delivery_status' => 'running',
                'delivery_error' => null,
            ]);

        if ($claimed === 0) {
            return;
        }

        $this->userMessage->refresh();

        // Broadcast thinking indicator
        $this->broadcastSafely(new ChatMessageSendingEvent($this->conversation->id, $agent->id));

        if ($agent->harness_type === HarnessType::OpenClaw) {
            try {
                $this->handleOpenClaw($openClawChat);

                return;
            } catch (Throwable $e) {
                $this->handleFailure($e);

                return;
            }
        }

        $textContent = $this->userMessage->textContent();

        // Build attachments from user message content blocks
        $attachments = $this->buildAttachments();

        try {
            $client = (new GatewayClient($agent->server))->forAgent($agent);

            $responseBlocks = $client->chatSendAndWait(
                $this->conversation->session_key,
                $agent->harness_agent_id,
                $textContent,
                $attachments,
            );

            if (! $responseBlocks) {
                $this->markFailed();

                return;
            }

            // Process response content blocks — save base64 images to storage
            $processedBlocks = $this->processResponseBlocks($responseBlocks);

            if (empty($processedBlocks)) {
                $this->markFailed('The agent did not return a readable response.');

                return;
            }

            // Create assistant message
            $assistantMessage = $this->conversation->messages()->create([
                'role' => ChatMessageRole::Assistant,
                'reply_to_message_id' => $this->userMessage->id,
                'content' => $processedBlocks,
                'sent_at' => now(),
            ]);

            $this->conversation->update(['last_message_at' => now()]);
            $this->userMessage->forceFill([
                'delivery_status' => 'completed',
                'delivery_error' => null,
                'outbound_to_agent_at' => now(),
            ])->save();

            $this->broadcastSafely(new ChatMessageReceivedEvent($assistantMessage));
        } catch (Throwable $e) {
            $this->handleFailure($e);
        }
    }

    private function handleOpenClaw(OpenClawChatService $openClawChat): void
    {
        $result = $openClawChat->sendAndWait(
            $this->conversation,
            $this->userMessage,
            cancelled: function (): bool {
                $this->userMessage->refresh();

                return $this->userMessage->delivery_status === 'aborted';
            },
        );

        $assistantMessage = DB::transaction(function () use ($result): ?ChatMessage {
            $message = ChatMessage::query()
                ->whereKey($this->userMessage->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($message->delivery_status, ['queued', 'running'], true)) {
                return null;
            }

            $assistantMessage = $this->conversation->messages()->firstOrCreate([
                'upstream_id' => $result['upstream_id'],
            ], [
                'role' => ChatMessageRole::Assistant,
                'reply_to_message_id' => $message->id,
                'content' => $result['content'],
                'sent_at' => now(),
            ]);

            $message->forceFill([
                'delivery_status' => 'completed',
                'delivery_error' => null,
                'upstream_run_id' => $result['run_id'],
                'outbound_to_agent_at' => now(),
            ])->save();

            return $assistantMessage;
        });

        if (! $assistantMessage) {
            return;
        }

        $this->userMessage->refresh();
        $this->conversation->update(['last_message_at' => now()]);

        if ($assistantMessage->wasRecentlyCreated) {
            $this->broadcastSafely(new ChatMessageReceivedEvent($assistantMessage));
        }
    }

    private function handleFailure(Throwable $e): void
    {
        $this->userMessage->refresh();
        if (in_array($this->userMessage->delivery_status, ['completed', 'aborted'], true)) {
            return;
        }

        if ($this->attempts() < $this->tries) {
            Log::error('SendAgentChatMessageJob failed', [
                'conversation_id' => $this->conversation->id,
                'exception' => $e::class,
                'attempt' => $this->attempts(),
            ]);

            throw $e;
        }

        $this->markFailed($this->safeFailureMessage($e));
    }

    public function failed(?Throwable $e): void
    {
        $this->markFailed($e ? $this->safeFailureMessage($e) : 'The agent did not respond in time.');
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
                $extension = pathinfo($fileName, PATHINFO_EXTENSION);
                $safeExtension = preg_replace('/[^a-z0-9]/i', '', $extension);
                $storedFileName = strtolower((string) Str::ulid()).($safeExtension ? ".{$safeExtension}" : '');
                $path = "chat-attachments/{$this->conversation->id}/{$storedFileName}";

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

        $this->broadcastSafely(new ChatMessageErrorEvent(
            $this->conversation->id,
            $message,
        ));
    }

    private function broadcastSafely(object $event): void
    {
        try {
            event($event);
        } catch (Throwable $e) {
            Log::warning('Chat realtime broadcast could not be queued', [
                'conversation_id' => $this->conversation->id,
                'event' => $event::class,
                'exception' => $e::class,
            ]);
        }
    }

    private function markFailed(string $message = 'The agent did not respond in time.'): void
    {
        $updated = ChatMessage::query()
            ->whereKey($this->userMessage->getKey())
            ->whereIn('delivery_status', ['queued', 'running'])
            ->update([
                'delivery_status' => 'failed',
                'delivery_error' => $message,
            ]);

        if ($updated === 0) {
            return;
        }

        $this->userMessage->refresh();

        $this->broadcastError($message);
    }

    private function safeFailureMessage(Throwable $e): string
    {
        return in_array($e->getMessage(), [
            'The agent Gateway is not available.',
            'The agent Gateway could not be reached.',
            'The agent Gateway did not accept the message.',
            'The agent Gateway rejected the request.',
            'The response was stopped.',
            'The agent did not respond in time.',
            'One of the attached files is no longer available.',
        ], true) ? $e->getMessage() : 'The agent did not respond in time.';
    }
}
