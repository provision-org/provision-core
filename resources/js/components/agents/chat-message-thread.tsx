import { useEffect, useRef } from 'react';
import AgentAvatar from '@/components/agents/agent-avatar';
import { cn } from '@/lib/utils';
import type { Agent, ChatMessage, ChatContentBlock } from '@/types';

function ContentBlock({ block }: { block: ChatContentBlock }) {
    if (block.type === 'text') {
        return <p className="whitespace-pre-wrap">{block.text}</p>;
    }

    if (block.type === 'image') {
        return (
            <a
                href={block.url}
                target="_blank"
                rel="noopener noreferrer"
                className="block"
            >
                <img
                    src={block.url}
                    alt={block.fileName}
                    className="mt-1 max-h-64 rounded-lg border object-contain"
                    loading="lazy"
                />
            </a>
        );
    }

    if (block.type === 'file') {
        return (
            <a
                href={block.url}
                target="_blank"
                rel="noopener noreferrer"
                className="mt-1 inline-flex items-center gap-1.5 rounded-md border bg-background px-3 py-1.5 text-sm hover:bg-accent"
            >
                📎 {block.fileName}
            </a>
        );
    }

    return null;
}

function MessageBubble({
    message,
    agent,
}: {
    message: ChatMessage;
    agent: Agent;
}) {
    const isUser = message.role === 'user';

    return (
        <div
            className={cn(
                'flex gap-3',
                isUser ? 'flex-row-reverse' : 'flex-row',
            )}
        >
            {!isUser && (
                <AgentAvatar
                    agent={agent}
                    className="size-7 shrink-0 text-xs"
                />
            )}

            <div
                className={cn(
                    'max-w-[75%] space-y-1 rounded-2xl px-4 py-2.5 text-sm',
                    isUser ? 'bg-primary text-primary-foreground' : 'bg-muted',
                )}
            >
                {message.content.map((block, i) => (
                    <ContentBlock key={i} block={block} />
                ))}
            </div>
        </div>
    );
}

function StreamingBubble({ text, agent }: { text: string; agent: Agent }) {
    return (
        <div className="flex gap-3">
            <AgentAvatar agent={agent} className="size-7 shrink-0 text-xs" />

            <div className="max-w-[75%] space-y-1 rounded-2xl bg-muted px-4 py-2.5 text-sm">
                <p className="whitespace-pre-wrap">
                    {text}
                    <span className="ml-0.5 inline-block h-4 w-0.5 animate-pulse bg-foreground/70" />
                </p>
            </div>
        </div>
    );
}

export default function ChatMessageThread({
    messages,
    agent,
    isThinking,
    streamingText,
}: {
    messages: ChatMessage[];
    agent: Agent;
    isThinking: boolean;
    streamingText: string | null;
}) {
    const bottomRef = useRef<HTMLDivElement>(null);
    const isStreaming = streamingText !== null;

    useEffect(() => {
        bottomRef.current?.scrollIntoView({ behavior: 'smooth' });
    }, [messages.length, isThinking, streamingText]);

    return (
        <div className="flex-1 overflow-y-auto px-4 py-4">
            {messages.length === 0 && !isThinking && !isStreaming && (
                <div className="flex h-full items-center justify-center">
                    <div className="text-center">
                        <AgentAvatar
                            agent={agent}
                            className="mx-auto size-12 text-lg"
                        />
                        <p className="mt-3 text-sm font-medium">
                            Start a conversation with {agent.name}
                        </p>
                        <p className="mt-1 text-xs text-muted-foreground">
                            Send a message to get started
                        </p>
                    </div>
                </div>
            )}

            <div className="space-y-4">
                {messages.map((msg) => (
                    <MessageBubble key={msg.id} message={msg} agent={agent} />
                ))}

                {isStreaming && (
                    <StreamingBubble text={streamingText} agent={agent} />
                )}

                {isThinking && !isStreaming && (
                    <div className="flex gap-3">
                        <AgentAvatar
                            agent={agent}
                            className="size-7 shrink-0 text-xs"
                        />
                        <div className="rounded-2xl bg-muted px-4 py-2.5 text-sm">
                            <div className="flex items-center gap-1">
                                <span className="animate-bounce">·</span>
                                <span className="animate-bounce [animation-delay:150ms]">
                                    ·
                                </span>
                                <span className="animate-bounce [animation-delay:300ms]">
                                    ·
                                </span>
                            </div>
                        </div>
                    </div>
                )}
            </div>

            <div ref={bottomRef} />
        </div>
    );
}
