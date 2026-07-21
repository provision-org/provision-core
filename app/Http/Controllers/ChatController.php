<?php

namespace App\Http\Controllers;

use App\Enums\AgentStatus;
use App\Enums\ChatMessageRole;
use App\Enums\HarnessType;
use App\Events\ChatMessageErrorEvent;
use App\Events\ChatMessageReceivedEvent;
use App\Http\Requests\SendChatMessageRequest;
use App\Jobs\SendAgentChatMessageJob;
use App\Models\Agent;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Services\GatewayClient;
use App\Services\OpenClawChatService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatController extends Controller
{
    private const QUEUE_FAILURE_MESSAGE = 'The chat request could not be queued. Please try again.';

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

        $server = $agent->server;
        $browserAvailable = (bool) ($server?->isDocker()
            || ($server?->ipv4_address && $server?->vnc_password));

        return Inertia::render('agents/chat', [
            'agent' => $agent,
            'conversations' => $conversations,
            'browserAvailable' => $browserAvailable,
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
            ->where('is_internal', false)
            ->orderBy('sent_at')
            ->orderBy('id')
            ->get()
            ->map(fn ($msg) => [
                'id' => $msg->id,
                'chat_conversation_id' => $msg->chat_conversation_id,
                'role' => $msg->role->value,
                'content' => $msg->contentWithUrls(),
                'sent_at' => $msg->sent_at->toISOString(),
                'delivery_status' => $msg->delivery_status,
                'delivery_error' => $msg->delivery_error,
                'upstream_run_id' => $msg->upstream_run_id,
            ]);

        $activeMessage = $conversation->messages()
            ->where('role', ChatMessageRole::User)
            ->whereIn('delivery_status', ['queued', 'running'])
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->first();

        if ($activeMessage?->delivery_status === 'queued'
            && $activeMessage->enqueued_at === null
            && $activeMessage->sent_at->lte(now()->subSeconds(10))) {
            $this->dispatchChatMessage($conversation, $activeMessage);
            $activeMessage->refresh();
        }

        return response()->json([
            'conversation' => [
                'id' => $conversation->id,
                'title' => $conversation->title,
                'session_key' => $conversation->session_key,
                'last_message_at' => $conversation->last_message_at?->toISOString(),
            ],
            'messages' => $messages,
            'active_run' => $activeMessage ? [
                'message_id' => $activeMessage->id,
                'run_id' => $activeMessage->upstream_run_id,
                'status' => $activeMessage->delivery_status,
            ] : null,
        ]);
    }

    public function store(Agent $agent, SendChatMessageRequest $request): JsonResponse
    {
        $team = $request->user()->currentTeam;
        abort_unless($agent->team_id === $team->id, 404);

        $clientMessageId = $this->clientMessageId($request);
        $existing = $this->existingClientMessage($clientMessageId, $agent, $request->user()->id);

        if ($existing) {
            $this->recoverQueueFailure($existing->conversation, $existing);

            return $this->storedMessageResponse($existing->conversation, $existing);
        }

        try {
            [$conversation, $userMessage, $created] = DB::transaction(function () use ($agent, $request, $clientMessageId) {
                $existing = $this->existingClientMessage($clientMessageId, $agent, $request->user()->id);
                if ($existing) {
                    return [$existing->conversation, $existing, false];
                }

                $ulid = strtolower((string) Str::ulid());
                $text = trim((string) $request->input('content', ''));
                $firstAttachment = $request->file('attachments.0');

                $conversation = ChatConversation::query()->create([
                    'agent_id' => $agent->id,
                    'user_id' => $request->user()->id,
                    'title' => Str::limit(
                        $text !== '' ? $text : ($firstAttachment?->getClientOriginalName() ?? 'New conversation'),
                        80,
                    ),
                    'session_key' => "web:{$ulid}",
                    'last_message_at' => now(),
                ]);

                $userMessage = $conversation->messages()->create([
                    'role' => ChatMessageRole::User,
                    'client_message_id' => $clientMessageId,
                    'content' => $this->buildContentBlocks($request, $conversation),
                    'sent_at' => now(),
                    'delivery_status' => 'queued',
                ]);

                return [$conversation, $userMessage, true];
            });
        } catch (QueryException $e) {
            $existing = $this->existingClientMessage($clientMessageId, $agent, $request->user()->id);
            if (! $existing) {
                throw $e;
            }

            $this->recoverQueueFailure($existing->conversation, $existing);

            return $this->storedMessageResponse($existing->conversation, $existing);
        }

        if ($created) {
            $this->dispatchChatMessage($conversation, $userMessage);
        } else {
            $this->recoverQueueFailure($conversation, $userMessage);
        }

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
                'delivery_status' => $userMessage->delivery_status,
                'client_message_id' => $userMessage->client_message_id,
            ],
        ], 201);
    }

    /**
     * Open a new conversation with a hidden onboarding prompt so the agent
     * introduces itself first. Used by the post-creation flow — the user
     * lands on chat and immediately sees the agent typing instead of
     * staring at an empty thread.
     *
     * Idempotent: if any conversation already exists for this user+agent,
     * we return the most recent one and skip the kickoff.
     */
    public function kickoff(Agent $agent, Request $request): JsonResponse
    {
        $team = $request->user()->currentTeam;
        abort_unless($agent->team_id === $team->id, 404);

        if ($agent->status !== AgentStatus::Active) {
            return response()->json(['error' => 'Agent is not ready yet.'], 409);
        }

        $existing = ChatConversation::query()
            ->where('agent_id', $agent->id)
            ->where('user_id', $request->user()->id)
            ->orderByDesc('last_message_at')
            ->first();

        if ($existing) {
            $failedKickoff = $existing->messages()
                ->where('is_internal', true)
                ->where('delivery_status', 'failed')
                ->where('delivery_error', self::QUEUE_FAILURE_MESSAGE)
                ->orderByDesc('sent_at')
                ->orderByDesc('id')
                ->first();

            if ($failedKickoff) {
                $this->recoverQueueFailure($existing, $failedKickoff);
            }

            return response()->json([
                'conversation' => [
                    'id' => $existing->id,
                    'title' => $existing->title,
                    'last_message_at' => $existing->last_message_at?->toISOString(),
                    'created_at' => $existing->created_at->toISOString(),
                ],
                'kicked_off' => false,
            ]);
        }

        $ulid = strtolower((string) Str::ulid());

        $conversation = ChatConversation::query()->create([
            'agent_id' => $agent->id,
            'user_id' => $request->user()->id,
            'title' => 'Onboarding',
            'session_key' => "web:{$ulid}",
            'last_message_at' => now(),
        ]);

        $kickoffMessage = $conversation->messages()->create([
            'role' => ChatMessageRole::User,
            'content' => [[
                'type' => 'text',
                'text' => "Hi! I just hired you — this is our first conversation. Read your ONBOARDING.md and use it to introduce yourself: say hello, tell me who you are and your role, then walk me through the specific onboarding steps you'll work through. If your ONBOARDING.md lists particular tools (like Linear, Notion, Ahrefs, etc.), name them explicitly and tell me how you plan to connect each one (sign up yourself, ask me for an API key, or request OAuth). Finish by asking what I want you to focus on. Don't mention internal filenames (ONBOARDING.md, IDENTITY.md, etc.) — just talk to me like a new teammate would.",
            ]],
            'is_internal' => true,
            'sent_at' => now(),
            'delivery_status' => 'queued',
        ]);

        $this->dispatchChatMessage($conversation, $kickoffMessage);

        return response()->json([
            'conversation' => [
                'id' => $conversation->id,
                'title' => $conversation->title,
                'last_message_at' => $conversation->last_message_at->toISOString(),
                'created_at' => $conversation->created_at->toISOString(),
            ],
            'kicked_off' => true,
        ], 201);
    }

    public function sendMessage(Agent $agent, ChatConversation $conversation, SendChatMessageRequest $request): JsonResponse
    {
        $team = $request->user()->currentTeam;
        abort_unless($agent->team_id === $team->id, 404);
        abort_unless($conversation->agent_id === $agent->id, 404);
        abort_unless($conversation->user_id === $request->user()->id, 404);

        [$userMessage, $created] = $this->createMessageForConversation(
            $agent,
            $conversation,
            $request,
        );

        if ($created) {
            $this->dispatchChatMessage($conversation, $userMessage);
        } else {
            $this->recoverQueueFailure($conversation, $userMessage);
        }

        return response()->json([
            'message' => [
                'id' => $userMessage->id,
                'chat_conversation_id' => $userMessage->chat_conversation_id,
                'role' => $userMessage->role->value,
                'content' => $userMessage->contentWithUrls(),
                'sent_at' => $userMessage->sent_at->toISOString(),
                'delivery_status' => $userMessage->delivery_status,
                'client_message_id' => $userMessage->client_message_id,
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

        [$userMessage, $created] = $this->createMessageForConversation(
            $agent,
            $conversation,
            $request,
        );

        if (! $created) {
            $this->recoverQueueFailure($conversation, $userMessage);

            return $this->duplicateStreamResponse($conversation, $userMessage);
        }

        if ($agent->harness_type === HarnessType::OpenClaw) {
            $this->dispatchChatMessage($conversation, $userMessage);

            return new StreamedResponse(function () use ($userMessage) {
                $this->sendSseEvent('message', [
                    'id' => $userMessage->id,
                    'chat_conversation_id' => $userMessage->chat_conversation_id,
                    'role' => $userMessage->role->value,
                    'content' => $userMessage->contentWithUrls(),
                    'sent_at' => $userMessage->sent_at->toISOString(),
                    'delivery_status' => $userMessage->delivery_status,
                    'client_message_id' => $userMessage->client_message_id,
                ]);
                $this->sendSseEvent('handoff', ['transport' => 'openclaw-gateway']);
            }, 200, [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'X-Accel-Buffering' => 'no',
            ]);
        }

        $textContent = $userMessage->textContent();
        $attachments = $this->buildAttachmentsFromMessage($userMessage);

        return new StreamedResponse(function () use ($agent, $conversation, $textContent, $attachments, $userMessage) {
            $userMessage->forceFill(['delivery_status' => 'running'])->save();

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
                        'done' => (function () use ($chunk, $conversation, &$fullText, $userMessage) {
                            // Use the streamed text as the final content if available
                            $finalBlocks = $fullText !== ''
                                ? $this->processStreamedBlocks([['type' => 'text', 'text' => $fullText]])
                                : $this->processStreamedBlocks($chunk['content'] ?? []);

                            if (empty($finalBlocks)) {
                                $finalBlocks = [['type' => 'text', 'text' => $fullText]];
                            }

                            $assistantMessage = $conversation->messages()->create([
                                'role' => ChatMessageRole::Assistant,
                                'reply_to_message_id' => $userMessage->id,
                                'content' => $finalBlocks,
                                'sent_at' => now(),
                            ]);

                            $conversation->update(['last_message_at' => now()]);

                            $userMessage->forceFill([
                                'delivery_status' => 'completed',
                                'delivery_error' => null,
                                'outbound_to_agent_at' => now(),
                            ])->save();

                            $this->broadcastSafely(
                                new ChatMessageReceivedEvent($assistantMessage),
                                $conversation->id,
                            );

                            $this->sendSseEvent('done', [
                                'id' => $assistantMessage->id,
                                'chat_conversation_id' => $assistantMessage->chat_conversation_id,
                                'role' => $assistantMessage->role->value,
                                'content' => $assistantMessage->contentWithUrls(),
                                'sent_at' => $assistantMessage->sent_at->toISOString(),
                            ]);
                        })(),
                        'error' => (function () use ($chunk, $userMessage) {
                            $message = $chunk['message'] ?? 'An unexpected error occurred.';
                            $userMessage->forceFill([
                                'delivery_status' => 'failed',
                                'delivery_error' => $message,
                            ])->save();
                            $this->sendSseEvent('error', [
                                'message' => $message,
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

                $userMessage->forceFill([
                    'delivery_status' => 'failed',
                    'delivery_error' => 'An unexpected error occurred while streaming the response.',
                ])->save();

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

    public function abort(
        Agent $agent,
        ChatConversation $conversation,
        Request $request,
        OpenClawChatService $openClawChat,
    ): JsonResponse {
        $team = $request->user()->currentTeam;
        abort_unless($agent->team_id === $team->id, 404);
        abort_unless($conversation->agent_id === $agent->id, 404);
        abort_unless($conversation->user_id === $request->user()->id, 404);

        if ($agent->harness_type !== HarnessType::OpenClaw) {
            return response()->json(['error' => 'Stopping a response is not available for this agent.'], 409);
        }

        $message = DB::transaction(function () use ($conversation): ?ChatMessage {
            $message = $conversation->messages()
                ->where('role', ChatMessageRole::User)
                ->whereIn('delivery_status', ['queued', 'running'])
                ->orderByDesc('sent_at')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if (! $message) {
                return null;
            }

            $message->forceFill([
                'delivery_status' => 'aborted',
                'delivery_error' => 'Response stopped.',
            ])->save();

            return $message;
        });

        if (! $message) {
            return response()->json(['aborted' => false]);
        }

        $runId = $message->upstream_run_id;

        if (is_string($runId) && $runId !== '') {
            try {
                $openClawChat->abort($conversation, $runId);
            } catch (\Throwable $e) {
                Log::warning('Could not immediately abort OpenClaw chat run', [
                    'conversation_id' => $conversation->id,
                    'exception' => $e::class,
                ]);
            }
        }

        $this->broadcastSafely(
            new ChatMessageErrorEvent($conversation->id, 'Response stopped.'),
            $conversation->id,
        );

        return response()->json(['aborted' => true]);
    }

    public function attachment(ChatConversation $conversation, string $filename, Request $request): StreamedResponse
    {
        abort_unless($request->hasValidSignature(), 403);

        $path = "chat-attachments/{$conversation->id}/{$filename}";

        abort_unless(Storage::exists($path), 404);

        return Storage::download($path, $filename);
    }

    /**
     * @return array{ChatMessage, bool}
     */
    private function createMessageForConversation(
        Agent $agent,
        ChatConversation $conversation,
        SendChatMessageRequest $request,
    ): array {
        $clientMessageId = $this->clientMessageId($request);

        try {
            return DB::transaction(function () use ($agent, $conversation, $request, $clientMessageId) {
                $lockedConversation = ChatConversation::query()
                    ->whereKey($conversation->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $existing = $this->existingClientMessage(
                    $clientMessageId,
                    $agent,
                    $request->user()->id,
                    $lockedConversation,
                );
                if ($existing) {
                    return [$existing, false];
                }

                $hasActiveMessage = $lockedConversation->messages()
                    ->where('role', ChatMessageRole::User)
                    ->whereIn('delivery_status', ['queued', 'running'])
                    ->exists();

                abort_if(
                    $hasActiveMessage,
                    409,
                    'Wait for the current response or stop it before sending another message.',
                );

                $message = $lockedConversation->messages()->create([
                    'role' => ChatMessageRole::User,
                    'client_message_id' => $clientMessageId,
                    'content' => $this->buildContentBlocks($request, $lockedConversation),
                    'sent_at' => now(),
                    'delivery_status' => 'queued',
                ]);

                $lockedConversation->update(['last_message_at' => now()]);

                return [$message, true];
            });
        } catch (QueryException $e) {
            $existing = $this->existingClientMessage(
                $clientMessageId,
                $agent,
                $request->user()->id,
                $conversation,
            );
            if (! $existing) {
                throw $e;
            }

            return [$existing, false];
        }
    }

    private function clientMessageId(SendChatMessageRequest $request): ?string
    {
        $value = trim((string) $request->input('client_message_id', ''));

        return $value !== '' ? $value : null;
    }

    private function existingClientMessage(
        ?string $clientMessageId,
        Agent $agent,
        string $userId,
        ?ChatConversation $expectedConversation = null,
    ): ?ChatMessage {
        if ($clientMessageId === null) {
            return null;
        }

        $message = ChatMessage::query()
            ->with('conversation')
            ->where('client_message_id', $clientMessageId)
            ->first();

        if (! $message) {
            return null;
        }

        $authorized = $message->conversation->agent_id === $agent->id
            && $message->conversation->user_id === $userId
            && ($expectedConversation === null
                || $message->chat_conversation_id === $expectedConversation->id);

        abort_unless($authorized, 409, 'That message identifier has already been used.');

        return $message;
    }

    private function storedMessageResponse(ChatConversation $conversation, ChatMessage $message): JsonResponse
    {
        return response()->json([
            'conversation' => [
                'id' => $conversation->id,
                'title' => $conversation->title,
                'last_message_at' => $conversation->last_message_at?->toISOString(),
                'created_at' => $conversation->created_at->toISOString(),
            ],
            'message' => [
                'id' => $message->id,
                'chat_conversation_id' => $message->chat_conversation_id,
                'role' => $message->role->value,
                'content' => $message->contentWithUrls(),
                'sent_at' => $message->sent_at->toISOString(),
                'delivery_status' => $message->delivery_status,
                'client_message_id' => $message->client_message_id,
            ],
            'idempotent_replay' => true,
        ]);
    }

    private function duplicateStreamResponse(
        ChatConversation $conversation,
        ChatMessage $message,
    ): StreamedResponse {
        return new StreamedResponse(function () use ($conversation, $message) {
            $this->sendSseEvent('message', [
                'id' => $message->id,
                'chat_conversation_id' => $message->chat_conversation_id,
                'role' => $message->role->value,
                'content' => $message->contentWithUrls(),
                'sent_at' => $message->sent_at->toISOString(),
                'delivery_status' => $message->delivery_status,
                'client_message_id' => $message->client_message_id,
            ]);

            if (in_array($message->delivery_status, ['failed', 'aborted'], true)) {
                $this->sendSseEvent('error', [
                    'message' => $message->delivery_error ?? 'The agent could not complete that request.',
                ]);

                return;
            }

            if ($message->delivery_status === 'completed') {
                $assistantMessage = $conversation->messages()
                    ->where('role', ChatMessageRole::Assistant)
                    ->where('reply_to_message_id', $message->id)
                    ->first();

                $assistantMessage ??= $conversation->messages()
                    ->where('role', ChatMessageRole::Assistant)
                    ->where(function ($query) use ($message) {
                        $query->where('sent_at', '>', $message->sent_at)
                            ->orWhere(function ($query) use ($message) {
                                $query->where('sent_at', $message->sent_at)
                                    ->where('id', '>', $message->id);
                            });
                    })
                    ->orderBy('sent_at')
                    ->orderBy('id')
                    ->first();

                if ($assistantMessage) {
                    $this->sendSseEvent('done', [
                        'id' => $assistantMessage->id,
                        'chat_conversation_id' => $assistantMessage->chat_conversation_id,
                        'role' => $assistantMessage->role->value,
                        'content' => $assistantMessage->contentWithUrls(),
                        'sent_at' => $assistantMessage->sent_at->toISOString(),
                    ]);

                    return;
                }
            }

            $this->sendSseEvent('handoff', ['transport' => 'durable-replay']);
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    private function dispatchChatMessage(ChatConversation $conversation, ChatMessage $message): void
    {
        try {
            SendAgentChatMessageJob::dispatch($conversation, $message);

            ChatMessage::query()
                ->whereKey($message->getKey())
                ->where('delivery_status', 'queued')
                ->update(['enqueued_at' => now()]);
        } catch (\Throwable $e) {
            ChatMessage::query()
                ->whereKey($message->getKey())
                ->where('delivery_status', 'queued')
                ->update([
                    'delivery_status' => 'failed',
                    'delivery_error' => self::QUEUE_FAILURE_MESSAGE,
                    'enqueued_at' => null,
                ]);

            Log::error('Chat message could not be queued', [
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
                'exception' => $e::class,
            ]);

            abort(503, self::QUEUE_FAILURE_MESSAGE);
        }
    }

    private function recoverQueueFailure(ChatConversation $conversation, ChatMessage $message): bool
    {
        $recovered = ChatMessage::query()
            ->whereKey($message->getKey())
            ->where('delivery_status', 'failed')
            ->where('delivery_error', self::QUEUE_FAILURE_MESSAGE)
            ->update([
                'delivery_status' => 'queued',
                'delivery_error' => null,
                'enqueued_at' => null,
            ]);

        if ($recovered === 0) {
            return false;
        }

        $message->refresh();
        $this->dispatchChatMessage($conversation, $message);

        return true;
    }

    private function broadcastSafely(object $event, string $conversationId): void
    {
        try {
            event($event);
        } catch (\Throwable $e) {
            Log::warning('Chat realtime broadcast could not be queued', [
                'conversation_id' => $conversationId,
                'event' => $event::class,
                'exception' => $e::class,
            ]);
        }
    }

    /**
     * Build content blocks from request text + file attachments.
     *
     * @return list<array<string, mixed>>
     */
    private function buildContentBlocks(SendChatMessageRequest $request, ChatConversation $conversation): array
    {
        $blocks = [];
        $text = trim((string) $request->input('content', ''));

        if ($text !== '') {
            $blocks[] = ['type' => 'text', 'text' => $text];
        }

        if ($request->hasFile('attachments')) {
            foreach ($request->file('attachments') as $file) {
                $fileName = $file->getClientOriginalName();
                $extension = $file->guessExtension();
                $storedFileName = strtolower((string) Str::ulid()).($extension ? ".{$extension}" : '');
                $path = $file->storeAs("chat-attachments/{$conversation->id}", $storedFileName);

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
