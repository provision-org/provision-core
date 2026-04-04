import { Head, usePage } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { useCallback, useRef, useState } from 'react';
import AgentAvatar from '@/components/agents/agent-avatar';
import ChatConversationList from '@/components/agents/chat-conversation-list';
import ChatInput from '@/components/agents/chat-input';
import ChatMessageThread from '@/components/agents/chat-message-thread';
import { Button } from '@/components/ui/button';
import { useEcho } from '@/hooks/use-echo';
import AppLayout from '@/layouts/app-layout';
import type {
    Agent,
    BreadcrumbItem,
    ChatConversation,
    ChatMessage,
    SharedData,
} from '@/types';

type Props = {
    agent: Agent;
    conversations: ChatConversation[];
};

function csrfToken(): string {
    return decodeURIComponent(
        document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '',
    );
}

function fetchHeaders(): Record<string, string> {
    return {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-XSRF-TOKEN': csrfToken(),
    };
}

/**
 * Parse an SSE stream from a fetch Response, calling the handler for each event.
 */
async function readSseStream(
    response: Response,
    onEvent: (event: string, data: string) => void,
): Promise<void> {
    const reader = response.body?.getReader();
    if (!reader) return;

    const decoder = new TextDecoder();
    let buffer = '';

    while (true) {
        const { done, value } = await reader.read();
        if (done) break;

        buffer += decoder.decode(value, { stream: true });

        const parts = buffer.split('\n\n');
        // Keep the last incomplete chunk in the buffer
        buffer = parts.pop() ?? '';

        for (const part of parts) {
            let eventName = 'message';
            let eventData = '';

            for (const line of part.split('\n')) {
                if (line.startsWith('event: ')) {
                    eventName = line.slice(7);
                } else if (line.startsWith('data: ')) {
                    eventData = line.slice(6);
                }
            }

            if (eventData) {
                onEvent(eventName, eventData);
            }
        }
    }
}

export default function Chat({
    agent,
    conversations: initialConversations,
}: Props) {
    const { auth } = usePage<SharedData>().props;
    const teamId = auth.user.current_team_id;

    const [conversations, setConversations] =
        useState<ChatConversation[]>(initialConversations);
    const [activeConversationId, setActiveConversationId] = useState<
        string | null
    >(null);
    const [messages, setMessages] = useState<ChatMessage[]>([]);
    const [streamingText, setStreamingText] = useState<string | null>(null);
    const lastStreamedMessageId = useRef<string | null>(null);
    const [isThinking, setIsThinking] = useState(false);
    const [isLoading, setIsLoading] = useState(false);

    // Ref to avoid stale closures in useEcho callbacks
    const activeConversationRef = useRef(activeConversationId);
    activeConversationRef.current = activeConversationId;

    // Real-time events (fallback for non-streaming path)
    useEcho<{
        id: string;
        chat_conversation_id: string;
        role: 'user' | 'assistant';
        content: ChatMessage['content'];
        sent_at: string;
    }>(`team.${teamId}`, '.chat.message.received', (data) => {
        if (data.chat_conversation_id === activeConversationRef.current) {
            // Skip if we're streaming or if this message was already added by the stream
            if (streamingText !== null) return;
            if (data.id && data.id === lastStreamedMessageId.current) return;
            setMessages((prev) => {
                if (prev.some((m) => m.id === data.id)) return prev;
                return [...prev, data];
            });
            setIsThinking(false);
        }
    });

    useEcho<{ chat_conversation_id: string }>(
        `team.${teamId}`,
        '.chat.message.sending',
        (data) => {
            if (data.chat_conversation_id === activeConversationRef.current) {
                setIsThinking(true);
            }
        },
    );

    useEcho<{ chat_conversation_id: string; error_message: string }>(
        `team.${teamId}`,
        '.chat.message.error',
        (data) => {
            if (data.chat_conversation_id === activeConversationRef.current) {
                setIsThinking(false);
                setStreamingText(null);
            }
        },
    );

    const loadConversation = useCallback(
        async (conversation: ChatConversation) => {
            setActiveConversationId(conversation.id);
            setIsLoading(true);
            setIsThinking(false);
            setStreamingText(null);

            try {
                const res = await fetch(
                    `/agents/${agent.id}/chat/${conversation.id}`,
                    {
                        headers: fetchHeaders(),
                    },
                );
                const data = await res.json();
                setMessages(data.messages ?? []);
            } catch {
                setMessages([]);
            } finally {
                setIsLoading(false);
            }
        },
        [agent.id],
    );

    const handleNewChat = useCallback(() => {
        setActiveConversationId(null);
        setMessages([]);
        setIsThinking(false);
        setStreamingText(null);
    }, []);

    /**
     * Stream a message to an existing conversation via SSE.
     */
    const sendWithStreaming = useCallback(
        async (conversationId: string, formData: FormData, content: string) => {
            // Add optimistic user message
            const optimisticMsg: ChatMessage = {
                id: `temp-${Date.now()}`,
                chat_conversation_id: conversationId,
                role: 'user',
                content: [{ type: 'text', text: content }],
                sent_at: new Date().toISOString(),
            };
            setMessages((prev) => [...prev, optimisticMsg]);
            setIsThinking(true);

            try {
                const res = await fetch(
                    `/agents/${agent.id}/chat/${conversationId}/stream`,
                    {
                        method: 'POST',
                        body: formData,
                        headers: fetchHeaders(),
                    },
                );

                if (!res.ok || !res.body) {
                    throw new Error('Stream request failed');
                }

                await readSseStream(res, (event, data) => {
                    const parsed = JSON.parse(data);

                    switch (event) {
                        case 'message':
                            // Replace optimistic user message with real one
                            setMessages((prev) =>
                                prev.map((m) =>
                                    m.id === optimisticMsg.id ? parsed : m,
                                ),
                            );
                            // Start showing streaming state
                            setIsThinking(false);
                            setStreamingText('');
                            break;

                        case 'token':
                            setStreamingText(
                                (prev) => (prev ?? '') + parsed.text,
                            );
                            break;

                        case 'done':
                            // Add the final assistant message and clear streaming
                            if (parsed.id)
                                lastStreamedMessageId.current = parsed.id;
                            setMessages((prev) => {
                                if (prev.some((m) => m.id === parsed.id))
                                    return prev;
                                return [...prev, parsed];
                            });
                            setStreamingText(null);
                            break;

                        case 'error':
                            setIsThinking(false);
                            setStreamingText(null);
                            break;
                    }
                });

                // Ensure streaming state is cleared after stream ends
                setStreamingText(null);
                setIsThinking(false);
            } catch {
                // Fallback: clear streaming and let Reverb handle the response
                setStreamingText(null);
                setIsThinking(true);
            }
        },
        [agent.id],
    );

    const handleSend = useCallback(
        async (content: string, files: File[]) => {
            const formData = new FormData();
            formData.append('content', content);
            files.forEach((file) => formData.append('attachments[]', file));

            if (activeConversationId) {
                // Use streaming for existing conversations
                await sendWithStreaming(
                    activeConversationId,
                    formData,
                    content,
                );
            } else {
                // Create new conversation first, then stream
                setIsThinking(true);

                try {
                    const res = await fetch(`/agents/${agent.id}/chat`, {
                        method: 'POST',
                        body: formData,
                        headers: fetchHeaders(),
                    });
                    const data = await res.json();

                    const newConv = data.conversation;
                    setConversations((prev) => [newConv, ...prev]);
                    setActiveConversationId(newConv.id);
                    setMessages([data.message]);

                    // The job was already dispatched by store(), so use
                    // Reverb for the response (streaming will be used
                    // on subsequent messages in this conversation)
                } catch {
                    setIsThinking(false);
                }
            }
        },
        [agent.id, activeConversationId, sendWithStreaming],
    );

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Agents', href: '/agents' },
        { title: agent.name, href: `/agents/${agent.id}` },
        { title: 'Chat', href: `/agents/${agent.id}/chat` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Chat with ${agent.name}`} />

            <div className="flex min-h-0 flex-1">
                {/* Sidebar */}
                <div className="hidden w-72 shrink-0 border-r md:flex md:flex-col">
                    <ChatConversationList
                        conversations={conversations}
                        activeId={activeConversationId}
                        onSelect={loadConversation}
                        onNewChat={handleNewChat}
                    />
                </div>

                {/* Main chat area */}
                <div className="flex flex-1 flex-col">
                    {/* Chat header */}
                    <div className="flex shrink-0 items-center gap-3 border-b px-4 py-3">
                        <Button
                            variant="ghost"
                            size="icon"
                            className="size-8 md:hidden"
                            asChild
                        >
                            <a href={`/agents/${agent.id}`}>
                                <ArrowLeft className="size-4" />
                            </a>
                        </Button>
                        <AgentAvatar agent={agent} className="size-8 text-xs" />
                        <div>
                            <p className="text-sm font-medium">{agent.name}</p>
                            <p className="text-xs text-muted-foreground">
                                {activeConversationId
                                    ? conversations.find(
                                          (c) => c.id === activeConversationId,
                                      )?.title || 'Conversation'
                                    : 'New conversation'}
                            </p>
                        </div>
                    </div>

                    {isLoading ? (
                        <div className="flex flex-1 items-center justify-center">
                            <div className="text-sm text-muted-foreground">
                                Loading messages...
                            </div>
                        </div>
                    ) : (
                        <ChatMessageThread
                            messages={messages}
                            agent={agent}
                            isThinking={isThinking}
                            streamingText={streamingText}
                        />
                    )}

                    <ChatInput onSend={handleSend} disabled={isThinking} />
                </div>
            </div>
        </AppLayout>
    );
}
