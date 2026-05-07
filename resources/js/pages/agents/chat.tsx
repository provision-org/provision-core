import { Head, usePage } from '@inertiajs/react';
import { ArrowLeft, PanelLeftClose, PanelLeftOpen } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
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
    const [activityLabel, setActivityLabel] = useState<string | null>(null);
    const activeStreamId = useRef<string | null>(null);
    const [historyCollapsed, setHistoryCollapsed] = useState<boolean>(() => {
        if (typeof window === 'undefined') return false;
        return window.localStorage.getItem('chat-history-collapsed') === '1';
    });
    const kickoffAttempted = useRef(false);

    const toggleHistory = useCallback(() => {
        setHistoryCollapsed((prev) => {
            const next = !prev;
            try {
                window.localStorage.setItem(
                    'chat-history-collapsed',
                    next ? '1' : '0',
                );
            } catch {
                // localStorage unavailable — preference doesn't persist, fine
            }
            return next;
        });
    }, []);

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
            // Skip if this message was already added by the stream
            if (data.id && data.id === lastStreamedMessageId.current) return;
            setMessages((prev) => {
                if (prev.some((m) => m.id === data.id)) return prev;
                return [...prev, data];
            });
            setIsThinking(false);
            setActivityLabel(null);
            setStreamingText(null);
            activeStreamId.current = null;
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
                setActivityLabel(null);
                activeStreamId.current = null;
            }
        },
    );

    useEcho<{
        chat_conversation_id: string;
        kind: string;
        tool: string | null;
        label: string | null;
        phase: string | null;
    }>(`team.${teamId}`, '.chat.agent.activity', (data) => {
        if (data.chat_conversation_id !== activeConversationRef.current) return;
        if (data.kind === 'idle') {
            setActivityLabel(null);
            return;
        }
        setActivityLabel(data.label ?? data.tool ?? null);
        setIsThinking(true);
    });

    useEcho<{
        chat_conversation_id: string;
        stream_id: string;
        delta: string;
        cumulative: string;
        is_final: boolean;
    }>(`team.${teamId}`, '.chat.message.streaming', (data) => {
        if (data.chat_conversation_id !== activeConversationRef.current) return;
        if (data.is_final) {
            setStreamingText(null);
            activeStreamId.current = null;
            return;
        }
        activeStreamId.current = data.stream_id;
        setStreamingText(data.cumulative);
        setActivityLabel(null);
        setIsThinking(true);
    });

    const loadConversation = useCallback(
        async (conversation: ChatConversation) => {
            setActiveConversationId(conversation.id);
            setIsLoading(true);
            setIsThinking(false);
            setStreamingText(null);
            setActivityLabel(null);
            activeStreamId.current = null;

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

    // Polling fallback for environments where the Reverb WebSocket can't
    // reach the browser (e.g. expose tunnels in dev). Lifecycle is gated on
    // `activeConversationId` (not `isThinking`) so dismissing the typing
    // indicator from inside the poll doesn't tear the loop down. The poll
    // runs while `isThinkingRef.current` is true, dismisses the indicator on
    // the first new assistant message, and continues for a few cycles past
    // the last new message to catch trailing messages from agents that emit
    // multiple blocks back-to-back.
    const isThinkingRef = useRef(isThinking);
    isThinkingRef.current = isThinking;

    // Silent kickoff: when the post-creation flow drops the user here with
    // ?greet=1 and there are no conversations yet, ask the backend to insert
    // a hidden onboarding prompt so the agent introduces itself first. The
    // user sees a typing indicator immediately rather than an empty thread.
    useEffect(() => {
        if (kickoffAttempted.current) return;
        if (typeof window === 'undefined') return;

        const params = new URLSearchParams(window.location.search);
        if (params.get('greet') !== '1') return;
        if (conversations.length > 0) {
            kickoffAttempted.current = true;
            window.history.replaceState({}, '', window.location.pathname);
            return;
        }

        kickoffAttempted.current = true;
        setIsThinking(true);

        (async () => {
            try {
                const res = await fetch(`/agents/${agent.id}/chat/kickoff`, {
                    method: 'POST',
                    headers: fetchHeaders(),
                });
                if (!res.ok) throw new Error(`Kickoff returned ${res.status}`);
                const data = await res.json();
                if (data?.conversation?.id) {
                    setConversations((prev) => [data.conversation, ...prev]);
                    setActiveConversationId(data.conversation.id);
                    setMessages([]);
                }
            } catch (err) {
                setIsThinking(false);
                console.error('Failed to kick off onboarding chat', err);
            } finally {
                window.history.replaceState({}, '', window.location.pathname);
            }
        })();
    }, [agent.id, conversations.length]);

    useEffect(() => {
        if (!activeConversationId) return;

        let stopped = false;
        let attempts = 0;
        const maxAttempts = 90; // 3 minutes at 2s cadence
        let pollsSinceLastNew = 0;
        const trailingIdleCycles = 5; // ~10s of quiet after last block before stopping
        let firstReplySeen = false;

        const poll = async () => {
            if (stopped) return;
            // If we've waited the full window without any reply, surface a
            // soft failure: dismiss the typing indicator and re-enable the
            // composer so the user isn't stuck. The kickoff message can be
            // dropped during a gateway restart cycle, and without this
            // recovery the chat would lock up indefinitely.
            if (attempts >= maxAttempts) {
                if (!firstReplySeen) {
                    setIsThinking(false);
                    setStreamingText(null);
                }
                stopped = true;
                return;
            }
            // Only poll while we're actively waiting for an assistant reply.
            if (!isThinkingRef.current && !firstReplySeen) {
                setTimeout(poll, 2000);
                return;
            }
            attempts += 1;
            try {
                const res = await fetch(
                    `/agents/${agent.id}/chat/${activeConversationId}`,
                    { headers: fetchHeaders() },
                );
                const data = await res.json();
                const fresh: ChatMessage[] = data.messages ?? [];

                let foundNew = false;
                setMessages((prev) => {
                    const known = new Set(prev.map((m) => m.id));
                    const newAssistant = fresh.filter(
                        (m) =>
                            m.role === 'assistant' && !known.has(m.id),
                    );
                    if (newAssistant.length === 0) return prev;
                    foundNew = true;
                    return [...prev, ...newAssistant];
                });

                if (foundNew) {
                    pollsSinceLastNew = 0;
                    if (!firstReplySeen) {
                        firstReplySeen = true;
                        setIsThinking(false);
                        setStreamingText(null);
                    }
                } else if (firstReplySeen) {
                    pollsSinceLastNew += 1;
                    if (pollsSinceLastNew >= trailingIdleCycles) {
                        stopped = true;
                    }
                }
            } catch {
                // ignore and retry
            }
            if (!stopped) {
                setTimeout(poll, 2000);
            }
        };

        const t = setTimeout(poll, 2000);
        return () => {
            stopped = true;
            clearTimeout(t);
        };
    }, [activeConversationId, agent.id]);

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

            let sawHandoff = false;

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

                        case 'handoff':
                            // OpenClaw agents flow through the provision-web
                            // channel. The reply comes via Reverb or polling
                            // fallback, not this stream — keep isThinking true
                            // so the fallback effect stays armed after close.
                            sawHandoff = true;
                            setStreamingText(null);
                            setIsThinking(true);
                            break;
                    }
                });

                // Ensure streaming state is cleared after stream ends, but
                // keep isThinking armed when handoff handed the reply off to
                // an out-of-band channel.
                setStreamingText(null);
                if (!sawHandoff) {
                    setIsThinking(false);
                }
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

                    if (!res.ok) {
                        throw new Error(`Server returned ${res.status}`);
                    }

                    const data = await res.json();

                    if (!data?.conversation?.id || !data?.message) {
                        throw new Error('Malformed response from server');
                    }

                    setConversations((prev) => [data.conversation, ...prev]);
                    setActiveConversationId(data.conversation.id);
                    setMessages([data.message]);

                    // The job was already dispatched by store(), so use
                    // Reverb for the response (streaming will be used
                    // on subsequent messages in this conversation).
                } catch (err) {
                    setIsThinking(false);
                    console.error('Failed to start chat conversation', err);
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
                {/* Conversation history sidebar — collapsible on md+ */}
                <div
                    className={`hidden shrink-0 overflow-hidden border-r transition-[width] duration-200 ease-out md:flex md:flex-col ${
                        historyCollapsed ? 'w-0 border-r-0' : 'w-72'
                    }`}
                >
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
                        <Button
                            variant="ghost"
                            size="icon"
                            className="hidden size-8 md:inline-flex"
                            onClick={toggleHistory}
                            aria-label={
                                historyCollapsed
                                    ? 'Show conversation history'
                                    : 'Hide conversation history'
                            }
                            title={
                                historyCollapsed
                                    ? 'Show conversation history'
                                    : 'Hide conversation history'
                            }
                        >
                            {historyCollapsed ? (
                                <PanelLeftOpen className="size-4" />
                            ) : (
                                <PanelLeftClose className="size-4" />
                            )}
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
                            activityLabel={activityLabel}
                        />
                    )}

                    <ChatInput
                        key={activeConversationId ?? 'new'}
                        onSend={handleSend}
                        disabled={isThinking}
                    />
                </div>
            </div>
        </AppLayout>
    );
}
