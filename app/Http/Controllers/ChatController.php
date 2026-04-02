<?php

namespace App\Http\Controllers;

use App\Enums\ChatMessageRole;
use App\Http\Requests\SendChatMessageRequest;
use App\Jobs\SendAgentChatMessageJob;
use App\Models\Agent;
use App\Models\ChatConversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
}
