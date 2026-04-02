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
    const [isThinking, setIsThinking] = useState(false);
    const [isLoading, setIsLoading] = useState(false);

    // Ref to avoid stale closures in useEcho callbacks
    const activeConversationRef = useRef(activeConversationId);
    activeConversationRef.current = activeConversationId;

    // Real-time events
    useEcho<{
        id: string;
        chat_conversation_id: string;
        role: 'user' | 'assistant';
        content: ChatMessage['content'];
        sent_at: string;
    }>(`team.${teamId}`, '.chat.message.received', (data) => {
        if (data.chat_conversation_id === activeConversationRef.current) {
            setMessages((prev) => [...prev, data]);
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
            }
        },
    );

    const loadConversation = useCallback(
        async (conversation: ChatConversation) => {
            setActiveConversationId(conversation.id);
            setIsLoading(true);
            setIsThinking(false);

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
    }, []);

    const handleSend = useCallback(
        async (content: string, files: File[]) => {
            const formData = new FormData();
            formData.append('content', content);
            files.forEach((file) => formData.append('attachments[]', file));

            if (activeConversationId) {
                // Send to existing conversation
                const optimisticMsg: ChatMessage = {
                    id: `temp-${Date.now()}`,
                    chat_conversation_id: activeConversationId,
                    role: 'user',
                    content: [{ type: 'text', text: content }],
                    sent_at: new Date().toISOString(),
                };
                setMessages((prev) => [...prev, optimisticMsg]);
                setIsThinking(true);

                try {
                    const res = await fetch(
                        `/agents/${agent.id}/chat/${activeConversationId}`,
                        {
                            method: 'POST',
                            body: formData,
                            headers: fetchHeaders(),
                        },
                    );
                    const data = await res.json();

                    // Replace optimistic message with real one
                    setMessages((prev) =>
                        prev.map((m) =>
                            m.id === optimisticMsg.id ? data.message : m,
                        ),
                    );
                } catch {
                    setIsThinking(false);
                }
            } else {
                // Create new conversation
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
                } catch {
                    setIsThinking(false);
                }
            }
        },
        [agent.id, activeConversationId],
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
                        />
                    )}

                    <ChatInput onSend={handleSend} disabled={isThinking} />
                </div>
            </div>
        </AppLayout>
    );
}
