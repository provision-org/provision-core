import { Head, Link } from '@inertiajs/react';
import {
    AlertCircle,
    ArrowLeft,
    CalendarClock,
    CheckCircle2,
    ChevronDown,
    ExternalLink,
    FolderOpen,
    LayoutGrid,
    LoaderCircle,
    Monitor,
    PanelLeftClose,
    PanelLeftOpen,
    RefreshCw,
    Radio,
    Settings,
    Square,
    Sparkles,
    WifiOff,
    X,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import {
    browserUrl as resolveBrowserUrl,
    index as agentsIndex,
    show as showAgent,
} from '@/actions/App/Http/Controllers/AgentController';
import {
    abort as abortChat,
    index as chatIndex,
    kickoff as kickoffChat,
    show as showChatConversation,
    store as storeChat,
    stream as streamChat,
} from '@/actions/App/Http/Controllers/ChatController';
import AgentAvatar from '@/components/agents/agent-avatar';
import ChatConversationList from '@/components/agents/chat-conversation-list';
import ChatInput from '@/components/agents/chat-input';
import ChatMessageThread from '@/components/agents/chat-message-thread';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useEcho } from '@/hooks/use-echo';
import AppLayout from '@/layouts/app-layout';
import type {
    Agent,
    BreadcrumbItem,
    ChatConversation,
    ChatDeliveryStatus,
    ChatMessage,
} from '@/types';

type Props = {
    agent: Agent;
    conversations: ChatConversation[];
    browserAvailable?: boolean;
};

type ChatConnectionState =
    | 'ready'
    | 'sending'
    | 'waiting'
    | 'live'
    | 'recovering'
    | 'delayed';

type ChatErrorState = {
    message: string;
    canCheckAgain: boolean;
};

type PendingChatRun = {
    id: string;
    conversationId: string;
    messageId: string | null;
    upstreamRunId: string | null;
    startedAt: number;
    baselineAssistantIds: string[];
};

type ActiveChatRun = {
    message_id: string;
    run_id: string | null;
    status: Extract<ChatDeliveryStatus, 'queued' | 'running'>;
};

type ChatConversationPayload = {
    messages: ChatMessage[];
    active_run: ActiveChatRun | null;
};

const CHAT_POLL_INTERVAL_MS = 2_000;
const CHAT_REPLY_TIMEOUT_MS = 3 * 60_000;

function newRunId(): string {
    return (
        globalThis.crypto?.randomUUID?.() ??
        `chat-${Date.now()}-${Math.random().toString(16).slice(2)}`
    );
}

function firstValidationError(errors: unknown): string | null {
    if (!errors || typeof errors !== 'object') return null;

    for (const value of Object.values(errors)) {
        if (typeof value === 'string' && value.trim()) return value;
        if (Array.isArray(value)) {
            const message = value.find(
                (item): item is string =>
                    typeof item === 'string' && item.trim().length > 0,
            );
            if (message) return message;
        }
    }

    return null;
}

async function responseError(
    response: globalThis.Response,
    fallback: string,
): Promise<string> {
    try {
        const payload = (await response.json()) as {
            message?: unknown;
            error?: unknown;
            errors?: unknown;
        };
        const validationError = firstValidationError(payload.errors);
        if (validationError) return validationError;
        if (typeof payload.message === 'string' && payload.message.trim()) {
            return payload.message;
        }
        if (typeof payload.error === 'string' && payload.error.trim()) {
            return payload.error;
        }
    } catch {
        // The response was not JSON. Use the contextual fallback below.
    }

    return fallback;
}

function messageText(message: ChatMessage): string {
    return message.content
        .filter(
            (
                block,
            ): block is Extract<
                ChatMessage['content'][number],
                { type: 'text' }
            > => block.type === 'text',
        )
        .map((block) => block.text)
        .join('\n');
}

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
        buffer = buffer.replaceAll('\r\n', '\n');

        const parts = buffer.split('\n\n');
        // Keep the last incomplete chunk in the buffer
        buffer = parts.pop() ?? '';

        for (const part of parts) {
            let eventName = 'message';
            const eventData: string[] = [];

            for (const line of part.split('\n')) {
                if (line.startsWith('event: ')) {
                    eventName = line.slice(7);
                } else if (line.startsWith('data:')) {
                    eventData.push(line.slice(5).replace(/^ /, ''));
                }
            }

            if (eventData.length > 0) {
                onEvent(eventName, eventData.join('\n'));
            }
        }
    }
}

export default function Chat({
    agent,
    conversations: initialConversations,
    browserAvailable = false,
}: Props) {
    const activeConversationStorageKey = `provision-chat-active:${agent.id}`;

    // Live-browser side panel. The signed browser URL is minted fresh when the
    // panel opens so it never goes stale during a long conversation.
    const [browserOpen, setBrowserOpen] = useState(false);
    const [browserUrl, setBrowserUrl] = useState<string | null>(null);
    const [browserLoading, setBrowserLoading] = useState(false);

    const loadBrowserUrl = useCallback(async () => {
        setBrowserLoading(true);
        try {
            const res = await fetch(resolveBrowserUrl.url(agent), {
                headers: fetchHeaders(),
            });
            if (!res.ok) {
                throw new Error('Browser connection request failed.');
            }
            const data = await res.json();
            setBrowserUrl(data.url ?? null);
        } catch {
            setBrowserUrl(null);
        } finally {
            setBrowserLoading(false);
        }
    }, [agent]);

    const toggleBrowser = useCallback(() => {
        setBrowserOpen((open) => {
            const next = !open;
            if (next) void loadBrowserUrl();
            return next;
        });
    }, [loadBrowserUrl]);

    const [conversations, setConversations] =
        useState<ChatConversation[]>(initialConversations);
    const [activeConversationId, setActiveConversationId] = useState<
        string | null
    >(null);
    const [messages, setMessages] = useState<ChatMessage[]>([]);
    const messagesRef = useRef(messages);
    messagesRef.current = messages;
    const updateMessages = useCallback(
        (updater: (current: ChatMessage[]) => ChatMessage[]) => {
            setMessages((current) => {
                const next = updater(current);
                messagesRef.current = next;

                return next;
            });
        },
        [],
    );
    const [streamingText, setStreamingText] = useState<string | null>(null);
    const lastStreamedMessageId = useRef<string | null>(null);
    const [isThinking, setIsThinking] = useState(false);
    const [isLoading, setIsLoading] = useState(false);
    const [activityLabel, setActivityLabel] = useState<string | null>(null);
    const [chatError, setChatError] = useState<ChatErrorState | null>(null);
    const [connectionState, setConnectionState] =
        useState<ChatConnectionState>('ready');
    const [pendingRun, setPendingRun] = useState<PendingChatRun | null>(null);
    const pendingRunRef = useRef<PendingChatRun | null>(null);
    const pendingClientMessageRef = useRef<{
        signature: string;
        id: string;
    } | null>(null);
    const [isStopping, setIsStopping] = useState(false);
    const loadRequestRef = useRef(0);
    const loadAbortRef = useRef<AbortController | null>(null);
    const initialSelectionAttempted = useRef(false);
    const activeStreamId = useRef<string | null>(null);
    // Ref to avoid stale closures in realtime callbacks.
    const activeConversationRef = useRef(activeConversationId);
    activeConversationRef.current = activeConversationId;
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

    const rememberActiveConversation = useCallback(
        (conversationId: string | null) => {
            activeConversationRef.current = conversationId;
            setActiveConversationId(conversationId);
            try {
                if (conversationId) {
                    window.localStorage.setItem(
                        activeConversationStorageKey,
                        conversationId,
                    );
                } else {
                    window.localStorage.removeItem(
                        activeConversationStorageKey,
                    );
                }
            } catch {
                // Conversation restoration is best-effort.
            }
        },
        [activeConversationStorageKey],
    );

    const clearPendingRun = useCallback(() => {
        pendingRunRef.current = null;
        setPendingRun(null);
        activeStreamId.current = null;
        setStreamingText(null);
        setActivityLabel(null);
        setIsThinking(false);
        setIsStopping(false);
    }, []);

    const activatePendingRun = useCallback(
        (run: PendingChatRun, state: ChatConnectionState = 'waiting') => {
            pendingRunRef.current = run;
            setPendingRun(run);
            activeStreamId.current = null;
            setChatError(null);
            setActivityLabel(null);
            setStreamingText(null);
            setIsThinking(true);
            setConnectionState(state);
        },
        [],
    );

    const beginPendingRun = useCallback(
        (
            conversationId: string,
            options: {
                id?: string;
                messageId?: string | null;
                upstreamRunId?: string | null;
                startedAt?: number;
                baselineAssistantIds?: string[];
                state?: ChatConnectionState;
            } = {},
        ): PendingChatRun => {
            const run = {
                id: options.id ?? newRunId(),
                conversationId,
                messageId: options.messageId ?? null,
                upstreamRunId: options.upstreamRunId ?? null,
                startedAt: options.startedAt ?? Date.now(),
                baselineAssistantIds:
                    options.baselineAssistantIds ??
                    messagesRef.current
                        .filter((message) => message.role === 'assistant')
                        .map((message) => message.id),
            } satisfies PendingChatRun;
            activatePendingRun(run, options.state);

            return run;
        },
        [activatePendingRun],
    );

    const finishPendingRun = useCallback(
        (
            runId: string,
            options: {
                error?: string;
                state?: ChatConnectionState;
            } = {},
        ) => {
            if (pendingRunRef.current?.id !== runId) return;

            clearPendingRun();
            setConnectionState(options.state ?? 'ready');
            setChatError(
                options.error
                    ? {
                          message: options.error,
                          canCheckAgain: true,
                      }
                    : null,
            );
        },
        [clearPendingRun],
    );

    const handleRealtimeMessage = useCallback(
        (data: ChatMessage) => {
            if (data.chat_conversation_id !== activeConversationRef.current) {
                return;
            }

            if (data.id !== lastStreamedMessageId.current) {
                updateMessages((current) => {
                    if (current.some((message) => message.id === data.id)) {
                        return current;
                    }

                    return [...current, data];
                });
            }

            setActivityLabel(null);
            setStreamingText(null);
            activeStreamId.current = null;

            if (
                pendingRunRef.current?.conversationId ===
                data.chat_conversation_id
            ) {
                // The durable poll correlates this reply with the tracked user
                // message before settling the run. Realtime is only a fast UI
                // path because broadcast events do not yet carry the run ID.
                setIsThinking(true);
                setConnectionState('live');
            } else {
                setIsThinking(false);
                setConnectionState('ready');
                setChatError(null);
            }
        },
        [updateMessages],
    );

    const handleRealtimeSending = useCallback((conversationId: string) => {
        if (conversationId !== activeConversationRef.current) return;

        setIsThinking(true);
        setConnectionState('waiting');
    }, []);

    const handleRealtimeError = useCallback(
        (conversationId: string, message: string) => {
            if (conversationId !== activeConversationRef.current) return;

            const run = pendingRunRef.current;
            if (message === 'Response stopped.') {
                if (run?.conversationId === conversationId) {
                    finishPendingRun(run.id);
                } else {
                    setIsThinking(false);
                    setConnectionState('ready');
                    setChatError(null);
                }
                return;
            }

            if (run?.conversationId === conversationId) {
                finishPendingRun(run.id, {
                    error: message,
                    state: 'delayed',
                });
            }
        },
        [finishPendingRun],
    );

    const handleRealtimeActivity = useCallback(
        (data: {
            chat_conversation_id: string;
            kind: string;
            tool: string | null;
            label: string | null;
        }) => {
            if (data.chat_conversation_id !== activeConversationRef.current) {
                return;
            }
            if (data.kind === 'idle') {
                setActivityLabel(null);
                return;
            }

            setActivityLabel(data.label ?? data.tool ?? null);
            setIsThinking(true);
            setConnectionState('live');
        },
        [],
    );

    const handleRealtimeStreaming = useCallback(
        (data: {
            chat_conversation_id: string;
            stream_id: string;
            cumulative: string;
            is_final: boolean;
        }) => {
            if (data.chat_conversation_id !== activeConversationRef.current) {
                return;
            }

            const run = pendingRunRef.current;
            if (run?.upstreamRunId && run.upstreamRunId !== data.stream_id) {
                return;
            }
            if (
                activeStreamId.current &&
                activeStreamId.current !== data.stream_id
            ) {
                return;
            }
            if (data.is_final) {
                setStreamingText(null);
                activeStreamId.current = null;
                return;
            }

            activeStreamId.current = data.stream_id;
            setStreamingText(data.cumulative);
            setActivityLabel(null);
            setIsThinking(true);
            setConnectionState('live');
        },
        [],
    );

    const loadConversation = useCallback(
        async (
            conversation: ChatConversation,
        ): Promise<ChatConversationPayload | null> => {
            const requestId = ++loadRequestRef.current;
            loadAbortRef.current?.abort();
            const controller = new AbortController();
            loadAbortRef.current = controller;

            if (
                pendingRunRef.current &&
                pendingRunRef.current.conversationId !== conversation.id
            ) {
                clearPendingRun();
            }
            rememberActiveConversation(conversation.id);
            setIsLoading(true);
            setIsThinking(false);
            setStreamingText(null);
            setActivityLabel(null);
            activeStreamId.current = null;
            setChatError(null);
            setConnectionState('ready');

            try {
                const res = await fetch(
                    showChatConversation.url({
                        agent,
                        conversation,
                    }),
                    {
                        headers: fetchHeaders(),
                        signal: controller.signal,
                    },
                );
                if (!res.ok) {
                    throw new Error(
                        await responseError(
                            res,
                            'Unable to load this conversation.',
                        ),
                    );
                }
                const data = (await res.json()) as ChatConversationPayload;
                if (requestId !== loadRequestRef.current) return null;

                const freshMessages: ChatMessage[] = data.messages ?? [];
                messagesRef.current = freshMessages;
                setMessages(freshMessages);

                if (data.active_run) {
                    const existing = pendingRunRef.current;
                    const activeMessage = freshMessages.find(
                        (message) => message.id === data.active_run?.message_id,
                    );
                    const parsedStartedAt = activeMessage
                        ? Date.parse(activeMessage.sent_at)
                        : Number.NaN;
                    beginPendingRun(conversation.id, {
                        id:
                            existing?.messageId === data.active_run.message_id
                                ? existing.id
                                : undefined,
                        messageId: data.active_run.message_id,
                        upstreamRunId: data.active_run.run_id,
                        startedAt:
                            existing?.messageId === data.active_run.message_id
                                ? existing.startedAt
                                : Number.isFinite(parsedStartedAt)
                                  ? parsedStartedAt
                                  : Date.now(),
                        baselineAssistantIds:
                            existing?.messageId === data.active_run.message_id
                                ? existing.baselineAssistantIds
                                : freshMessages
                                      .filter(
                                          (message) =>
                                              message.role === 'assistant',
                                      )
                                      .map((message) => message.id),
                        state:
                            data.active_run.status === 'queued'
                                ? 'sending'
                                : 'waiting',
                    });
                } else {
                    const trackedRun = pendingRunRef.current;
                    if (trackedRun?.conversationId === conversation.id) {
                        const trackedMessage = freshMessages.find(
                            (message) => message.id === trackedRun.messageId,
                        );
                        const baseline = new Set(
                            trackedRun.baselineAssistantIds,
                        );
                        const hasNewAssistant = freshMessages.some(
                            (message) =>
                                message.role === 'assistant' &&
                                !baseline.has(message.id),
                        );

                        if (trackedMessage?.delivery_status === 'aborted') {
                            finishPendingRun(trackedRun.id);
                        } else if (
                            trackedMessage?.delivery_status === 'failed'
                        ) {
                            finishPendingRun(trackedRun.id, {
                                error:
                                    trackedMessage.delivery_error ??
                                    'The agent could not complete that request.',
                                state: 'delayed',
                            });
                        } else if (
                            trackedMessage?.delivery_status === 'completed' ||
                            hasNewAssistant
                        ) {
                            finishPendingRun(trackedRun.id);
                        } else {
                            finishPendingRun(trackedRun.id, {
                                error: 'The agent is no longer processing this request and did not return a reply.',
                                state: 'delayed',
                            });
                        }
                    } else {
                        const latestUserMessage = freshMessages.findLast(
                            (message) => message.role === 'user',
                        );
                        if (latestUserMessage?.delivery_status === 'aborted') {
                            setConnectionState('ready');
                            setChatError(null);
                        } else if (
                            latestUserMessage?.delivery_status === 'failed'
                        ) {
                            setConnectionState('delayed');
                            setChatError({
                                message:
                                    latestUserMessage.delivery_error ??
                                    'The agent could not complete the last request.',
                                canCheckAgain: true,
                            });
                        }
                    }
                }

                return data;
            } catch (error) {
                if (
                    controller.signal.aborted ||
                    requestId !== loadRequestRef.current
                ) {
                    return null;
                }

                messagesRef.current = [];
                setMessages([]);
                setChatError({
                    message:
                        error instanceof Error
                            ? error.message
                            : 'Unable to load this conversation.',
                    canCheckAgain: true,
                });
                setConnectionState('recovering');

                return null;
            } finally {
                if (requestId === loadRequestRef.current) {
                    setIsLoading(false);
                }
            }
        },
        [
            agent,
            beginPendingRun,
            clearPendingRun,
            finishPendingRun,
            rememberActiveConversation,
        ],
    );

    // Restore the last open conversation. Its durable active_run is the source
    // of truth after reload; no browser-only pending flag is needed.
    useEffect(() => {
        if (initialSelectionAttempted.current) return;
        if (typeof window === 'undefined') return;

        const params = new URLSearchParams(window.location.search);
        if (params.get('greet') === '1' && conversations.length === 0) return;

        initialSelectionAttempted.current = true;

        let activeId: string | null = null;

        try {
            activeId = window.localStorage.getItem(
                activeConversationStorageKey,
            );
        } catch {
            // Corrupt or unavailable storage should never block chat.
        }

        const target = conversations.find(
            (conversation) => conversation.id === activeId,
        );
        if (!target) return;

        void loadConversation(target);
    }, [activeConversationStorageKey, conversations, loadConversation]);

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
        setConnectionState('sending');
        setChatError(null);

        (async () => {
            try {
                const res = await fetch(kickoffChat.url(agent), {
                    method: 'POST',
                    headers: fetchHeaders(),
                });
                if (!res.ok) {
                    throw new Error(
                        await responseError(
                            res,
                            'The agent could not start its introduction.',
                        ),
                    );
                }
                const data = await res.json();
                if (data?.conversation?.id) {
                    setConversations((prev) => [data.conversation, ...prev]);
                    activeConversationRef.current = data.conversation.id;
                    rememberActiveConversation(data.conversation.id);

                    if (data.kicked_off) {
                        messagesRef.current = [];
                        setMessages([]);
                        beginPendingRun(data.conversation.id, {
                            baselineAssistantIds: [],
                            state: 'sending',
                        });
                    } else {
                        await loadConversation(data.conversation);
                    }
                }
            } catch (error) {
                setIsThinking(false);
                setConnectionState('delayed');
                setChatError({
                    message:
                        error instanceof Error
                            ? error.message
                            : 'The agent could not start its introduction.',
                    canCheckAgain: false,
                });
            } finally {
                window.history.replaceState({}, '', window.location.pathname);
            }
        })();
    }, [
        agent,
        beginPendingRun,
        conversations.length,
        loadConversation,
        rememberActiveConversation,
    ]);

    // Realtime is the fast path; this per-run poll is the durable source of
    // truth. It correlates the browser run with the queued user message and the
    // Gateway run ID returned by the show endpoint.
    useEffect(() => {
        if (!pendingRun) return;

        let stopped = false;
        let timer: ReturnType<typeof setTimeout> | null = null;
        let consecutiveFailures = 0;
        let missingActivePolls = 0;
        let timeoutWarningShown = false;
        const controller = new AbortController();
        const run = pendingRun;

        const poll = async () => {
            if (stopped || pendingRunRef.current?.id !== run.id) return;

            if (
                !timeoutWarningShown &&
                Date.now() - run.startedAt >= CHAT_REPLY_TIMEOUT_MS
            ) {
                timeoutWarningShown = true;
                setConnectionState('delayed');
                setChatError({
                    message:
                        'The reply is taking longer than expected. The agent is still processing it; you can keep waiting or stop the response.',
                    canCheckAgain: false,
                });
            }

            try {
                const res = await fetch(
                    showChatConversation.url({
                        agent,
                        conversation: run.conversationId,
                    }),
                    {
                        headers: fetchHeaders(),
                        signal: controller.signal,
                    },
                );
                if (!res.ok) {
                    throw new Error(
                        await responseError(
                            res,
                            'Unable to check for the agent reply.',
                        ),
                    );
                }
                const data = (await res.json()) as ChatConversationPayload;
                const fresh: ChatMessage[] = data.messages ?? [];
                if (
                    stopped ||
                    pendingRunRef.current?.id !== run.id ||
                    controller.signal.aborted
                ) {
                    return;
                }

                consecutiveFailures = 0;
                if (activeConversationRef.current === run.conversationId) {
                    messagesRef.current = fresh;
                    setMessages(fresh);
                }

                const baseline = new Set(run.baselineAssistantIds);
                const hasNewAssistant = fresh.some(
                    (message) =>
                        message.role === 'assistant' &&
                        !baseline.has(message.id),
                );
                const trackedMessage = run.messageId
                    ? fresh.find((message) => message.id === run.messageId)
                    : null;
                const activeRun = data.active_run;
                const activeRunMatches =
                    activeRun != null &&
                    (run.messageId === null ||
                        activeRun.message_id === run.messageId);

                if (activeRunMatches) {
                    missingActivePolls = 0;

                    if (
                        run.messageId !== activeRun.message_id ||
                        run.upstreamRunId !== activeRun.run_id
                    ) {
                        const updatedRun = {
                            ...run,
                            messageId: activeRun.message_id,
                            upstreamRunId: activeRun.run_id,
                        } satisfies PendingChatRun;
                        pendingRunRef.current = updatedRun;
                        setPendingRun(updatedRun);
                        return;
                    }

                    if (
                        activeConversationRef.current === run.conversationId &&
                        !timeoutWarningShown
                    ) {
                        setConnectionState(
                            activeRun.status === 'queued'
                                ? 'sending'
                                : 'waiting',
                        );
                        setChatError((current) =>
                            current?.canCheckAgain ? current : null,
                        );
                    }
                } else if (trackedMessage?.delivery_status === 'aborted') {
                    finishPendingRun(run.id);
                    return;
                } else if (trackedMessage?.delivery_status === 'failed') {
                    finishPendingRun(run.id, {
                        error:
                            trackedMessage.delivery_error ??
                            'The agent could not complete that request.',
                        state: 'delayed',
                    });
                    return;
                } else if (
                    trackedMessage?.delivery_status === 'completed' ||
                    hasNewAssistant
                ) {
                    finishPendingRun(run.id);
                    return;
                } else if (!activeRun) {
                    missingActivePolls += 1;
                    if (missingActivePolls >= 2) {
                        finishPendingRun(run.id, {
                            error: 'The agent stopped processing this request without returning a reply.',
                            state: 'delayed',
                        });
                        return;
                    }
                }
            } catch (error) {
                if (controller.signal.aborted || stopped) return;

                consecutiveFailures += 1;
                if (activeConversationRef.current === run.conversationId) {
                    setConnectionState('recovering');
                    if (consecutiveFailures >= 3) {
                        setChatError({
                            message:
                                error instanceof Error
                                    ? `${error.message} We are still retrying automatically.`
                                    : 'The live connection was interrupted. We are still retrying automatically.',
                            canCheckAgain: false,
                        });
                    }
                }
            }

            if (!stopped) {
                timer = setTimeout(poll, CHAT_POLL_INTERVAL_MS);
            }
        };

        timer = setTimeout(poll, 750);
        return () => {
            stopped = true;
            controller.abort();
            if (timer) clearTimeout(timer);
        };
    }, [agent, finishPendingRun, pendingRun]);

    const handleNewChat = useCallback(() => {
        loadAbortRef.current?.abort();
        clearPendingRun();
        rememberActiveConversation(null);
        messagesRef.current = [];
        setMessages([]);
        setChatError(null);
        setConnectionState('ready');
    }, [clearPendingRun, rememberActiveConversation]);

    const handleStop = useCallback(async () => {
        const run = pendingRunRef.current;
        if (
            !run ||
            run.conversationId !== activeConversationRef.current ||
            agent.harness_type !== 'openclaw' ||
            isStopping
        ) {
            return;
        }

        setIsStopping(true);
        setChatError(null);

        try {
            const res = await fetch(
                abortChat.url({
                    agent,
                    conversation: run.conversationId,
                }),
                {
                    method: 'POST',
                    headers: fetchHeaders(),
                },
            );
            if (!res.ok) {
                throw new Error(
                    await responseError(res, 'Unable to stop the response.'),
                );
            }

            const data = (await res.json()) as { aborted?: boolean };
            if (pendingRunRef.current?.id !== run.id) return;

            if (data.aborted) {
                finishPendingRun(run.id);
                return;
            }

            const conversation = conversations.find(
                (candidate) => candidate.id === run.conversationId,
            );
            if (conversation) {
                await loadConversation(conversation);
            }
        } catch (error) {
            if (pendingRunRef.current?.id !== run.id) return;

            setConnectionState('delayed');
            setChatError({
                message:
                    error instanceof Error
                        ? error.message
                        : 'Unable to stop the response.',
                canCheckAgain: false,
            });
        } finally {
            setIsStopping(false);
        }
    }, [agent, conversations, finishPendingRun, isStopping, loadConversation]);

    /**
     * Stream a message to an existing conversation via SSE.
     */
    const sendWithStreaming = useCallback(
        async (
            conversationId: string,
            formData: FormData,
            content: string,
        ): Promise<boolean> => {
            const runId = newRunId();
            const startedAt = Date.now();
            const knownMessageIds = new Set(
                messagesRef.current.map((message) => message.id),
            );
            const baselineAssistantIds = messagesRef.current
                .filter((message) => message.role === 'assistant')
                .map((message) => message.id);
            const optimisticMsg: ChatMessage = {
                id: `temp-${runId}`,
                chat_conversation_id: conversationId,
                role: 'user',
                content: [{ type: 'text', text: content }],
                sent_at: new Date().toISOString(),
            };
            updateMessages((prev) => [...prev, optimisticMsg]);
            setIsThinking(true);
            setStreamingText(null);
            setActivityLabel(null);
            setChatError(null);
            setConnectionState('sending');

            let sawHandoff = false;
            let acknowledged = false;
            let completed = false;
            let streamFailure: string | null = null;

            try {
                const res = await fetch(
                    streamChat.url({ agent, conversation: conversationId }),
                    {
                        method: 'POST',
                        body: formData,
                        headers: fetchHeaders(),
                    },
                );

                if (!res.ok || !res.body) {
                    throw new Error(
                        await responseError(
                            res,
                            'The message could not be sent.',
                        ),
                    );
                }

                await readSseStream(res, (event, data) => {
                    const parsed = JSON.parse(data) as ChatMessage & {
                        message?: string;
                        text?: string;
                    };

                    switch (event) {
                        case 'message':
                            acknowledged = true;
                            if (
                                activeConversationRef.current !== conversationId
                            ) {
                                break;
                            }
                            // Replace optimistic user message with real one
                            updateMessages((prev) =>
                                prev.map((m) =>
                                    m.id === optimisticMsg.id ? parsed : m,
                                ),
                            );
                            beginPendingRun(conversationId, {
                                id: runId,
                                messageId: parsed.id,
                                upstreamRunId: parsed.upstream_run_id ?? null,
                                startedAt,
                                baselineAssistantIds,
                                state: 'sending',
                            });
                            setStreamingText('');
                            break;

                        case 'token':
                            if (
                                activeConversationRef.current !== conversationId
                            ) {
                                break;
                            }
                            if (pendingRunRef.current?.id !== runId) break;
                            setConnectionState('live');
                            setStreamingText(
                                (prev) => (prev ?? '') + (parsed.text ?? ''),
                            );
                            break;

                        case 'done':
                            if (
                                activeConversationRef.current !== conversationId
                            ) {
                                break;
                            }
                            if (pendingRunRef.current?.id !== runId) break;
                            completed = true;
                            // Add the final assistant message and clear streaming
                            if (parsed.id)
                                lastStreamedMessageId.current = parsed.id;
                            updateMessages((prev) => {
                                if (prev.some((m) => m.id === parsed.id))
                                    return prev;
                                return [...prev, parsed];
                            });
                            finishPendingRun(runId);
                            break;

                        case 'error':
                            streamFailure =
                                parsed.message ||
                                'The agent could not complete that request.';
                            if (
                                activeConversationRef.current !== conversationId
                            ) {
                                break;
                            }
                            if (acknowledged) {
                                finishPendingRun(runId, {
                                    error: streamFailure,
                                    state: 'delayed',
                                });
                            } else {
                                setIsThinking(false);
                                setStreamingText(null);
                                setConnectionState('delayed');
                                setChatError({
                                    message: streamFailure,
                                    canCheckAgain: false,
                                });
                            }
                            break;

                        case 'handoff':
                            if (
                                activeConversationRef.current !== conversationId
                            ) {
                                break;
                            }
                            // OpenClaw hands the durable message to its native
                            // Gateway run. The reply arrives via the private
                            // conversation channel or durable polling.
                            sawHandoff = true;
                            setStreamingText(null);
                            setIsThinking(true);
                            setConnectionState('waiting');
                            break;
                    }
                });

                if (!acknowledged) {
                    throw new Error(
                        streamFailure ??
                            'The server did not confirm that the message was sent.',
                    );
                }

                if (
                    activeConversationRef.current === conversationId &&
                    !completed &&
                    !streamFailure &&
                    !sawHandoff
                ) {
                    setConnectionState('recovering');
                }

                return true;
            } catch (error) {
                if (activeConversationRef.current !== conversationId) {
                    return acknowledged;
                }

                setStreamingText(null);

                // The request may have reached Laravel before the connection
                // broke. Reconcile once before restoring the draft, preventing
                // an unnecessary resend when the durable user message exists.
                if (!acknowledged) {
                    try {
                        const check = await fetch(
                            showChatConversation.url({
                                agent,
                                conversation: conversationId,
                            }),
                            { headers: fetchHeaders() },
                        );
                        if (check.ok) {
                            const data =
                                (await check.json()) as ChatConversationPayload;
                            const fresh: ChatMessage[] = data.messages ?? [];
                            const persistedUserMessage = fresh.find(
                                (message) =>
                                    message.role === 'user' &&
                                    !knownMessageIds.has(message.id) &&
                                    messageText(message).trim() === content &&
                                    Date.parse(message.sent_at) >=
                                        startedAt - 5_000,
                            );

                            if (persistedUserMessage) {
                                messagesRef.current = fresh;
                                setMessages(fresh);

                                if (
                                    persistedUserMessage.delivery_status ===
                                    'aborted'
                                ) {
                                    setIsThinking(false);
                                    setConnectionState('ready');
                                    setChatError(null);
                                } else if (
                                    persistedUserMessage.delivery_status ===
                                    'failed'
                                ) {
                                    setIsThinking(false);
                                    setConnectionState('delayed');
                                    setChatError({
                                        message:
                                            persistedUserMessage.delivery_error ??
                                            'The agent could not complete that request.',
                                        canCheckAgain: true,
                                    });
                                } else if (
                                    persistedUserMessage.delivery_status !==
                                    'completed'
                                ) {
                                    beginPendingRun(conversationId, {
                                        id: runId,
                                        messageId: persistedUserMessage.id,
                                        upstreamRunId:
                                            data.active_run?.message_id ===
                                            persistedUserMessage.id
                                                ? data.active_run.run_id
                                                : null,
                                        startedAt,
                                        baselineAssistantIds,
                                        state: 'recovering',
                                    });
                                } else {
                                    setIsThinking(false);
                                    setConnectionState('ready');
                                    setChatError(null);
                                }

                                return true;
                            }
                        }
                    } catch {
                        // Preserve the original send error below.
                    }
                }

                if (acknowledged && pendingRunRef.current?.id === runId) {
                    setIsThinking(true);
                    setConnectionState('recovering');
                    setChatError({
                        message:
                            'Your message was sent, but the live connection was interrupted. We are checking for the reply automatically.',
                        canCheckAgain: false,
                    });

                    return true;
                }

                updateMessages((prev) =>
                    prev.filter((message) => message.id !== optimisticMsg.id),
                );
                setIsThinking(false);
                setConnectionState('delayed');
                setChatError({
                    message:
                        error instanceof Error
                            ? `${error.message} Your draft has been kept.`
                            : 'The message could not be sent. Your draft has been kept.',
                    canCheckAgain: false,
                });

                return false;
            }
        },
        [agent, beginPendingRun, finishPendingRun, updateMessages],
    );

    const handleSend = useCallback(
        async (content: string, files: File[]): Promise<boolean> => {
            if (pendingRunRef.current) {
                setChatError({
                    message:
                        'Wait for the current response or stop it before sending another message.',
                    canCheckAgain: false,
                });
                return false;
            }

            const messageSignature = JSON.stringify([
                content,
                files.map((file) => [
                    file.name,
                    file.size,
                    file.type,
                    file.lastModified,
                ]),
            ]);
            const clientMessageId =
                pendingClientMessageRef.current?.signature === messageSignature
                    ? pendingClientMessageRef.current.id
                    : newRunId();
            pendingClientMessageRef.current = {
                signature: messageSignature,
                id: clientMessageId,
            };

            const formData = new FormData();
            formData.append('content', content);
            formData.append('client_message_id', clientMessageId);
            files.forEach((file) => formData.append('attachments[]', file));

            if (activeConversationId) {
                // Use streaming for existing conversations
                const accepted = await sendWithStreaming(
                    activeConversationId,
                    formData,
                    content,
                );
                if (accepted) {
                    pendingClientMessageRef.current = null;
                }

                return accepted;
            } else {
                const runId = newRunId();
                setIsThinking(true);
                setConnectionState('sending');
                setChatError(null);

                try {
                    const res = await fetch(storeChat.url(agent), {
                        method: 'POST',
                        body: formData,
                        headers: fetchHeaders(),
                    });

                    if (!res.ok) {
                        throw new Error(
                            await responseError(
                                res,
                                'The message could not be sent.',
                            ),
                        );
                    }

                    const data = await res.json();

                    if (!data?.conversation?.id || !data?.message) {
                        throw new Error('Malformed response from server');
                    }

                    setConversations((prev) => [data.conversation, ...prev]);
                    if (activeConversationRef.current !== null) {
                        return true;
                    }

                    rememberActiveConversation(data.conversation.id);
                    messagesRef.current = [data.message];
                    setMessages([data.message]);
                    beginPendingRun(data.conversation.id, {
                        id: runId,
                        messageId: data.message.id,
                        upstreamRunId: data.message.upstream_run_id ?? null,
                        baselineAssistantIds: [],
                        state: 'sending',
                    });
                    pendingClientMessageRef.current = null;

                    return true;
                } catch (error) {
                    if (activeConversationRef.current !== null) {
                        return false;
                    }

                    setIsThinking(false);
                    setConnectionState('delayed');
                    setChatError({
                        message:
                            error instanceof Error
                                ? `${error.message} Your draft has been kept.`
                                : 'The message could not be sent. Your draft has been kept.',
                        canCheckAgain: false,
                    });

                    return false;
                }
            }
        },
        [
            activeConversationId,
            agent,
            beginPendingRun,
            rememberActiveConversation,
            sendWithStreaming,
        ],
    );

    const handleCheckAgain = useCallback(async () => {
        if (!activeConversationId) return;

        const conversation = conversations.find(
            (candidate) => candidate.id === activeConversationId,
        );
        if (!conversation) return;

        setConnectionState('recovering');
        setChatError(null);
        await loadConversation(conversation);
    }, [activeConversationId, conversations, loadConversation]);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Agents', href: agentsIndex.url() },
        { title: agent.name, href: showAgent.url(agent) },
        { title: 'Chat', href: chatIndex.url(agent) },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            {activeConversationId && (
                <ConversationRealtimeEvents
                    key={activeConversationId}
                    conversationId={activeConversationId}
                    onMessage={handleRealtimeMessage}
                    onSending={handleRealtimeSending}
                    onError={handleRealtimeError}
                    onActivity={handleRealtimeActivity}
                    onStreaming={handleRealtimeStreaming}
                />
            )}
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
                            <Link href={showAgent.url(agent)}>
                                <ArrowLeft className="size-4" />
                            </Link>
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
                        <AgentSectionMenu
                            agent={agent}
                            subtitle={
                                activeConversationId
                                    ? conversations.find(
                                          (c) => c.id === activeConversationId,
                                      )?.title || 'Conversation'
                                    : 'New conversation'
                            }
                        />

                        <div className="ml-auto flex items-center gap-2">
                            <ChatConnectionStatus state={connectionState} />
                            {agent.harness_type === 'openclaw' &&
                                pendingRun?.conversationId ===
                                    activeConversationId && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        className="gap-2"
                                        onClick={() => void handleStop()}
                                        disabled={isStopping}
                                    >
                                        {isStopping ? (
                                            <LoaderCircle className="size-3.5 animate-spin" />
                                        ) : (
                                            <Square className="size-3 fill-current" />
                                        )}
                                        Stop
                                    </Button>
                                )}
                            {browserAvailable && (
                                <Button
                                    variant={
                                        browserOpen ? 'secondary' : 'ghost'
                                    }
                                    size="sm"
                                    className="hidden gap-2 lg:inline-flex"
                                    onClick={toggleBrowser}
                                    aria-pressed={browserOpen}
                                    title="Show the agent's live browser alongside chat"
                                >
                                    <Monitor className="size-4" />
                                    Browser
                                </Button>
                            )}
                        </div>
                    </div>

                    {chatError && (
                        <ChatErrorBanner
                            error={chatError}
                            onCheckAgain={() => void handleCheckAgain()}
                            onDismiss={() => {
                                setChatError(null);
                                if (!pendingRunRef.current) {
                                    setConnectionState('ready');
                                }
                            }}
                        />
                    )}

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
                        disabled={isThinking || isLoading || isStopping}
                    />
                </div>

                {/* Live browser side panel — collapsible on lg+ */}
                <div
                    className={`hidden shrink-0 overflow-hidden border-l transition-[width] duration-200 ease-out lg:flex lg:flex-col ${
                        browserOpen
                            ? 'w-[40vw] min-w-[420px]'
                            : 'w-0 border-l-0'
                    }`}
                >
                    <BrowserPanel
                        url={browserUrl}
                        loading={browserLoading}
                        onRefresh={loadBrowserUrl}
                        onClose={() => setBrowserOpen(false)}
                    />
                </div>
            </div>
        </AppLayout>
    );
}

type ConversationRealtimeEventHandlers = {
    conversationId: string;
    onMessage: (message: ChatMessage) => void;
    onSending: (conversationId: string) => void;
    onError: (conversationId: string, message: string) => void;
    onActivity: (data: {
        chat_conversation_id: string;
        kind: string;
        tool: string | null;
        label: string | null;
    }) => void;
    onStreaming: (data: {
        chat_conversation_id: string;
        stream_id: string;
        cumulative: string;
        is_final: boolean;
    }) => void;
};

/** Subscribe only after a conversation is selected, avoiding an invalid
 * placeholder private-channel authorization request on the new-chat view. */
function ConversationRealtimeEvents(props: ConversationRealtimeEventHandlers) {
    const handlersRef = useRef(props);
    const channel = `chat.conversation.${props.conversationId}`;

    useEffect(() => {
        handlersRef.current = props;
    }, [props]);

    useEcho<ChatMessage>(channel, '.chat.message.received', (data) => {
        handlersRef.current.onMessage(data);
    });
    useEcho<{ chat_conversation_id: string }>(
        channel,
        '.chat.message.sending',
        (data) => {
            handlersRef.current.onSending(data.chat_conversation_id);
        },
    );
    useEcho<{ chat_conversation_id: string; error_message: string }>(
        channel,
        '.chat.message.error',
        (data) => {
            handlersRef.current.onError(
                data.chat_conversation_id,
                data.error_message ||
                    'The agent could not complete that request.',
            );
        },
    );
    useEcho<{
        chat_conversation_id: string;
        kind: string;
        tool: string | null;
        label: string | null;
        phase: string | null;
    }>(channel, '.chat.agent.activity', (data) => {
        handlersRef.current.onActivity(data);
    });
    useEcho<{
        chat_conversation_id: string;
        stream_id: string;
        delta: string;
        cumulative: string;
        is_final: boolean;
    }>(channel, '.chat.message.streaming', (data) => {
        handlersRef.current.onStreaming(data);
    });

    return null;
}

function ChatConnectionStatus({ state }: { state: ChatConnectionState }) {
    const config = {
        ready: {
            label: 'Ready',
            title: 'Chat is ready',
            icon: CheckCircle2,
            className: 'text-emerald-600 dark:text-emerald-400',
        },
        sending: {
            label: 'Sending',
            title: 'Sending your message',
            icon: LoaderCircle,
            className: 'text-muted-foreground',
        },
        waiting: {
            label: 'Agent working',
            title: 'Waiting for the agent reply',
            icon: LoaderCircle,
            className: 'text-muted-foreground',
        },
        live: {
            label: 'Live',
            title: 'Receiving live agent updates',
            icon: Radio,
            className: 'text-emerald-600 dark:text-emerald-400',
        },
        recovering: {
            label: 'Reconnecting',
            title: 'The live connection was interrupted; checking history',
            icon: WifiOff,
            className: 'text-amber-600 dark:text-amber-400',
        },
        delayed: {
            label: 'Reply delayed',
            title: 'The request needs attention',
            icon: AlertCircle,
            className: 'text-destructive',
        },
    } satisfies Record<
        ChatConnectionState,
        {
            label: string;
            title: string;
            icon: typeof CheckCircle2;
            className: string;
        }
    >;
    const current = config[state];
    const StatusIcon = current.icon;
    const animated = state === 'sending' || state === 'waiting';

    return (
        <div
            role="status"
            title={current.title}
            className={`flex items-center gap-1.5 rounded-full border bg-background px-2.5 py-1 text-xs ${current.className}`}
        >
            <StatusIcon
                className={`size-3.5 ${animated ? 'animate-spin' : ''}`}
            />
            <span className="hidden sm:inline">{current.label}</span>
            <span className="sr-only sm:hidden">{current.label}</span>
        </div>
    );
}

function ChatErrorBanner({
    error,
    onCheckAgain,
    onDismiss,
}: {
    error: ChatErrorState;
    onCheckAgain: () => void;
    onDismiss: () => void;
}) {
    return (
        <div
            role="alert"
            className="flex shrink-0 items-start gap-3 border-b border-destructive/25 bg-destructive/5 px-4 py-3"
        >
            <AlertCircle className="mt-0.5 size-4 shrink-0 text-destructive" />
            <p className="min-w-0 flex-1 text-sm text-foreground">
                {error.message}
            </p>
            <div className="flex shrink-0 items-center gap-1">
                {error.canCheckAgain && (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="h-7"
                        onClick={onCheckAgain}
                    >
                        <RefreshCw className="size-3.5" />
                        Check again
                    </Button>
                )}
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="size-7"
                    onClick={onDismiss}
                    aria-label="Dismiss chat error"
                >
                    <X className="size-3.5" />
                </Button>
            </div>
        </div>
    );
}

/**
 * Agent header that doubles as a section switcher. Clicking the agent name
 * opens a menu linking to every other part of the agent (Overview, Files,
 * Knowledge, Scheduled Tasks, Channels, Settings) so the user can jump there
 * without leaving — and finding their way back from — chat. Each link targets
 * the agent page with a `#tab` hash, which the show page reads to open the
 * matching tab directly.
 */
function AgentSectionMenu({
    agent,
    subtitle,
}: {
    agent: Agent;
    subtitle: string;
}) {
    const base = `/agents/${agent.id}`;
    const isHermes = agent.harness_type === 'hermes';
    const isWorkforce = agent.agent_mode === 'workforce';

    const sections = [
        { label: 'Overview', href: base, icon: LayoutGrid, show: true },
        {
            label: 'Files',
            href: `${base}#workspace`,
            icon: FolderOpen,
            show: true,
        },
        {
            label: 'Knowledge',
            href: `${base}#memory`,
            icon: Sparkles,
            show: true,
        },
        {
            label: 'Scheduled tasks',
            href: `${base}#schedules`,
            icon: CalendarClock,
            show: true,
        },
        {
            label: 'Browser',
            href: `${base}#browser`,
            icon: Monitor,
            show: !isHermes,
        },
        {
            label: 'Channels',
            href: `${base}#channels`,
            icon: Radio,
            show: !isWorkforce,
        },
        {
            label: 'Settings',
            href: `${base}#settings`,
            icon: Settings,
            show: true,
        },
    ].filter((s) => s.show);

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button
                    type="button"
                    className="-ml-1 flex items-center gap-3 rounded-md px-2 py-1 text-left transition-colors hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    title="Go to another part of this agent"
                >
                    <AgentAvatar agent={agent} className="size-8 text-xs" />
                    <div className="min-w-0">
                        <p className="flex items-center gap-1 text-sm font-medium">
                            <span className="truncate">{agent.name}</span>
                            <ChevronDown className="size-3.5 shrink-0 text-muted-foreground" />
                        </p>
                        <p className="truncate text-xs text-muted-foreground">
                            {subtitle}
                        </p>
                    </div>
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" className="w-56">
                <DropdownMenuLabel>{agent.name}</DropdownMenuLabel>
                <DropdownMenuSeparator />
                {sections.map((s) => (
                    <DropdownMenuItem key={s.label} asChild>
                        {/* Full navigation (not an Inertia visit) so the #hash is
                            present when the agent page mounts and opens that tab. */}
                        <a href={s.href} className="cursor-pointer gap-2">
                            <s.icon className="size-4 text-muted-foreground" />
                            {s.label}
                        </a>
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

function BrowserPanel({
    url,
    loading,
    onRefresh,
    onClose,
}: {
    url: string | null;
    loading: boolean;
    onRefresh: () => void;
    onClose: () => void;
}) {
    return (
        <div className="flex h-full w-full flex-col">
            <div className="flex shrink-0 items-center gap-2 border-b px-3 py-2">
                <Monitor className="size-4 text-muted-foreground" />
                <span className="text-sm font-medium">Live browser</span>
                <div className="ml-auto flex items-center gap-1">
                    <Button
                        variant="ghost"
                        size="icon"
                        className="size-7"
                        onClick={onRefresh}
                        title="Reconnect"
                        disabled={loading}
                    >
                        <RefreshCw
                            className={`size-3.5 ${loading ? 'animate-spin' : ''}`}
                        />
                    </Button>
                    {url && (
                        <Button
                            variant="ghost"
                            size="icon"
                            className="size-7"
                            asChild
                            title="Open in new tab"
                        >
                            <a
                                href={url}
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                <ExternalLink className="size-3.5" />
                            </a>
                        </Button>
                    )}
                    <Button
                        variant="ghost"
                        size="icon"
                        className="size-7"
                        onClick={onClose}
                        title="Close browser"
                    >
                        <X className="size-3.5" />
                    </Button>
                </div>
            </div>

            <div className="relative min-h-0 flex-1 bg-muted/30">
                {loading && (
                    <div className="flex h-full items-center justify-center text-sm text-muted-foreground">
                        Connecting to browser…
                    </div>
                )}
                {!loading && !url && (
                    <div className="flex h-full flex-col items-center justify-center gap-2 px-6 text-center">
                        <Monitor className="size-8 text-muted-foreground/50" />
                        <p className="text-sm text-muted-foreground">
                            The browser isn&apos;t available yet. It comes
                            online once the agent&apos;s server is fully
                            provisioned.
                        </p>
                    </div>
                )}
                {!loading && url && (
                    <iframe
                        src={url}
                        title="Agent live browser"
                        className="absolute inset-0 size-full border-0"
                        allow="clipboard-read; clipboard-write"
                    />
                )}
            </div>
        </div>
    );
}
