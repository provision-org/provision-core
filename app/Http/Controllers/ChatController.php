<?php

namespace App\Http\Controllers;

use App\Enums\AgentStatus;
use App\Enums\ChatMessageRole;
use App\Events\ChatMessageReceivedEvent;
use App\Http\Requests\SendChatMessageRequest;
use App\Jobs\SendAgentChatMessageJob;
use App\Models\Agent;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Services\GatewayClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatController extends Controller
{
    public function index(Agent $agent, Request $request): Response
    {
        $team = $request->user()->currentTeam;
        abort_unless($agent->team_id === $team->id, 404);

        $conversations = ChatConversation::query()
            ->where('agent_id', $agent->id)
            ->where('user_id', $request->user()->id)
            ->orderByDesc('last_message_at')
            ->limit(50)
            ->get()
            ->map(fn (ChatConversation $c) => [
                'id' => $c->id,
                'title' => $c->title,
                'last_message_at' => $c->last_message_at?->toISOString(),
                'created_at' => $c->created_at->toISOString(),
            ]);

        return Inertia::render('agents/chat', [
            'agent' => $agent,
            'conversations' => $conversations,
        ]);
    }

    public function conversations(Agent $agent, Request $request): JsonResponse
    {
        $team = $request->user()->currentTeam;
        abort_unless($agent->team_id === $team->id, 404);

        $conversations = ChatConversation::query()
            ->where('agent_id', $agent->id)
            ->where('user_id', $request->user()->id)
            ->orderByDesc('last_message_at')
            ->paginate(50);

        return response()->json($conversations);
    }

    public function show(Agent $agent, ChatConversation $conversation, Request $request): JsonResponse
    {
        $team = $request->user()->currentTeam;
        abort_unless($agent->team_id === $team->id, 404);
        abort_unless($conversation->agent_id === $agent->id, 404);
        abort_unless($conversation->user_id === $request->user()->id, 404);

        $messages = $conversation->messages()
            ->orderBy('sent_at')
            ->get()
            ->map(fn ($msg) => [
                'id' => $msg->id,
                'chat_conversation_id' => $msg->chat_conversation_id,
                'role' => $msg->role->value,
                'content' => $msg->contentWithUrls(),
                'sent_at' => $msg->sent_at->toISOString(),
            ]);

        return response()->json([
            'conversation' => [
                'id' => $conversation->id,
                'title' => $conversation->title,
                'session_key' => $conversation->session_key,
                'last_message_at' => $conversation->last_message_at?->toISOString(),
            ],
            'messages' => $messages,
        ]);
    }

    public function store(Agent $agent, SendChatMessageRequest $request): JsonResponse
    {
        $team = $request->user()->currentTeam;
        abort_unless($agent->team_id === $team->id, 404);

        $ulid = strtolower((string) Str::ulid());

        $conversation = ChatConversation::query()->create([
            'agent_id' => $agent->id,
            'user_id' => $request->user()->id,
            'title' => Str::limit($request->input('content'), 80),
            'session_key' => "web:{$ulid}",
            'last_message_at' => now(),
        ]);

        $contentBlocks = $this->buildContentBlocks($request, $conversation);

        $userMessage = $conversation->messages()->create([
            'role' => ChatMessageRole::User,
            'content' => $contentBlocks,
            'sent_at' => now(),
        ]);

        SendAgentChatMessageJob::dispatch($conversation, $userMessage);

        return response()->json([
            'conversation' => [
                'id' => $conversation->id,
                'title' => $conversation->title,
                'last_message_at' => $conversation->last_message_at->toISOString(),
                'created_at' => $conversation->created_at->toISOString(),
            ],
            'message' => [
                'id' => $userMessage->id,
                'chat_conversation_id' => $userMessage->chat_conversation_id,
                'role' => $userMessage->role->value,
                'content' => $userMessage->contentWithUrls(),
                'sent_at' => $userMessage->sent_at->toISOString(),
            ],
        ], 201);
    }

    public function sendMessage(Agent $agent, ChatConversation $conversation, SendChatMessageRequest $request): JsonResponse
    {
        $team = $request->user()->currentTeam;
        abort_unless($agent->team_id === $team->id, 404);
        abort_unless($conversation->agent_id === $agent->id, 404);
        abort_unless($conversation->user_id === $request->user()->id, 404);

        $contentBlocks = $this->buildContentBlocks($request, $conversation);

        $userMessage = $conversation->messages()->create([
            'role' => ChatMessageRole::User,
            'content' => $contentBlocks,
            'sent_at' => now(),
        ]);

        $conversation->update(['last_message_at' => now()]);

        SendAgentChatMessageJob::dispatch($conversation, $userMessage);

        return response()->json([
            'message' => [
                'id' => $userMessage->id,
                'chat_conversation_id' => $userMessage->chat_conversation_id,
                'role' => $userMessage->role->value,
                'content' => $userMessage->contentWithUrls(),
                'sent_at' => $userMessage->sent_at->toISOString(),
            ],
        ]);
    }

    /**
     * Stream an assistant response via Server-Sent Events.
     *
     * Accepts the user message, saves it to DB, then opens an SSE stream
     * that yields tokens as they arrive from the agent gateway.
     */
    public function stream(Agent $agent, ChatConversation $conversation, SendChatMessageRequest $request): StreamedResponse
    {
        $team = $request->user()->currentTeam;
        abort_unless($agent->team_id === $team->id, 404);
        abort_unless($conversation->agent_id === $agent->id, 404);
        abort_unless($conversation->user_id === $request->user()->id, 404);

        $conversation->loadMissing('agent.server');

        if ($agent->status !== AgentStatus::Active || ! $agent->server) {
            return $this->sseErrorResponse('Agent is not available.');
        }

        $contentBlocks = $this->buildContentBlocks($request, $conversation);

        $userMessage = $conversation->messages()->create([
            'role' => ChatMessageRole::User,
            'content' => $contentBlocks,
            'sent_at' => now(),
        ]);

        $conversation->update(['last_message_at' => now()]);

        $textContent = $userMessage->textContent();
        $attachments = $this->buildAttachmentsFromMessage($userMessage);

        return new StreamedResponse(function () use ($agent, $conversation, $textContent, $attachments, $userMessage) {
            // Send the saved user message ID first so the frontend can reconcile
            $this->sendSseEvent('message', [
                'id' => $userMessage->id,
                'chat_conversation_id' => $userMessage->chat_conversation_id,
                'role' => $userMessage->role->value,
                'content' => $userMessage->contentWithUrls(),
                'sent_at' => $userMessage->sent_at->toISOString(),
            ]);

            try {
                $client = (new GatewayClient($agent->server))->forAgent($agent);
                $fullText = '';

                foreach ($client->chatSendAndStream(
                    $conversation->session_key,
                    $agent->harness_agent_id,
                    $textContent,
                    $attachments,
                ) as $chunk) {
                    match ($chunk['type']) {
                        'token' => (function () use ($chunk, &$fullText) {
                            $fullText .= $chunk['text'];
                            $this->sendSseEvent('token', ['text' => $chunk['text']]);
                        })(),
                        'done' => (function () use ($chunk, $conversation, &$fullText, $agent) {
                            // Use the streamed text as the final content if available
                            $finalBlocks = $fullText !== ''
                                ? $this->processStreamedBlocks([['type' => 'text', 'text' => $fullText]])
                                : $this->processStreamedBlocks($chunk['content'] ?? []);

                            if (empty($finalBlocks)) {
                                $finalBlocks = [['type' => 'text', 'text' => $fullText]];
                            }

                            $assistantMessage = $conversation->messages()->create([
                                'role' => ChatMessageRole::Assistant,
                                'content' => $finalBlocks,
                                'sent_at' => now(),
                            ]);

                            $conversation->update(['last_message_at' => now()]);

                            event(new ChatMessageReceivedEvent($assistantMessage, $agent->team_id));

                            $this->sendSseEvent('done', [
                                'id' => $assistantMessage->id,
                                'chat_conversation_id' => $assistantMessage->chat_conversation_id,
                                'role' => $assistantMessage->role->value,
                                'content' => $assistantMessage->contentWithUrls(),
                                'sent_at' => $assistantMessage->sent_at->toISOString(),
                            ]);
                        })(),
                        'error' => (function () use ($chunk) {
                            $this->sendSseEvent('error', [
                                'message' => $chunk['message'] ?? 'An unexpected error occurred.',
                            ]);
                        })(),
                        default => null,
                    };
                }
            } catch (\Throwable $e) {
                Log::error('Chat stream failed', [
                    'conversation_id' => $conversation->id,
                    'error' => $e->getMessage(),
                ]);

                $this->sendSseEvent('error', [
                    'message' => 'An unexpected error occurred while streaming the response.',
                ]);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    public function attachment(ChatConversation $conversation, string $filename, Request $request): StreamedResponse
    {
        abort_unless($request->hasValidSignature(), 403);

        $path = "chat-attachments/{$conversation->id}/{$filename}";

        abort_unless(Storage::exists($path), 404);

        return Storage::download($path, $filename);
    }

    /**
     * Build content blocks from request text + file attachments.
     *
     * @return list<array<string, mixed>>
     */
    private function buildContentBlocks(SendChatMessageRequest $request, ChatConversation $conversation): array
    {
        $blocks = [['type' => 'text', 'text' => $request->input('content')]];

        if ($request->hasFile('attachments')) {
            foreach ($request->file('attachments') as $file) {
                $fileName = $file->getClientOriginalName();
                $path = $file->storeAs("chat-attachments/{$conversation->id}", $fileName);

                $mimeType = $file->getMimeType() ?? 'application/octet-stream';
                $type = str_starts_with($mimeType, 'image/') ? 'image' : 'file';

                $blocks[] = [
                    'type' => $type,
                    'path' => $path,
                    'fileName' => $fileName,
                    'mimeType' => $mimeType,
                ];
            }
        }

        return $blocks;
    }

    /**
     * Build attachment data from a saved user message's content blocks.
     *
     * @return list<array{type: string, mimeType: string, fileName: string, path: string}>
     */
    private function buildAttachmentsFromMessage(ChatMessage $message): array
    {
        $attachments = [];

        foreach ($message->content as $block) {
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
     * Process streamed response blocks — strip OpenClaw routing prefixes.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return list<array<string, mixed>>
     */
    private function processStreamedBlocks(array $blocks): array
    {
        $processed = [];

        foreach ($blocks as $block) {
            if (($block['type'] ?? null) === 'text') {
                $text = preg_replace('/^\[\[reply_to_\w+\]\]\s*/', '', $block['text'] ?? '');
                $processed[] = ['type' => 'text', 'text' => $text];
            } elseif (in_array($block['type'] ?? null, ['image', 'file'])) {
                $processed[] = $block;
            }
        }

        return $processed;
    }

    /**
     * Send a Server-Sent Event.
     *
     * @param  array<string, mixed>  $data
     */
    private function sendSseEvent(string $event, array $data): void
    {
        echo "event: {$event}\n";
        echo 'data: '.json_encode($data)."\n\n";

        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }

    /**
     * Return an immediate SSE error response.
     */
    private function sseErrorResponse(string $message): StreamedResponse
    {
        return new StreamedResponse(function () use ($message) {
            $this->sendSseEvent('error', ['message' => $message]);
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
