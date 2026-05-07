import { useEffect, useRef } from 'react';
import Markdown from 'react-markdown';
import AgentAvatar from '@/components/agents/agent-avatar';
import { cn } from '@/lib/utils';
import type { Agent, ChatMessage, ChatContentBlock } from '@/types';

const markdownComponents = {
    p: ({ children }: { children?: React.ReactNode }) => (
        <p className="whitespace-pre-wrap [&:not(:first-child)]:mt-2">
            {children}
        </p>
    ),
    strong: ({ children }: { children?: React.ReactNode }) => (
        <strong className="font-semibold">{children}</strong>
    ),
    em: ({ children }: { children?: React.ReactNode }) => (
        <em className="italic">{children}</em>
    ),
    a: ({ href, children }: { href?: string; children?: React.ReactNode }) => (
        <a
            href={href}
            target="_blank"
            rel="noopener noreferrer"
            className="underline underline-offset-2 hover:opacity-80"
        >
            {children}
        </a>
    ),
    ul: ({ children }: { children?: React.ReactNode }) => (
        <ul className="my-2 ml-4 list-disc space-y-1">{children}</ul>
    ),
    ol: ({ children }: { children?: React.ReactNode }) => (
        <ol className="my-2 ml-4 list-decimal space-y-1">{children}</ol>
    ),
    li: ({ children }: { children?: React.ReactNode }) => (
        <li className="leading-snug">{children}</li>
    ),
    h1: ({ children }: { children?: React.ReactNode }) => (
        <h1 className="mt-3 mb-1 text-base font-semibold">{children}</h1>
    ),
    h2: ({ children }: { children?: React.ReactNode }) => (
        <h2 className="mt-3 mb-1 text-sm font-semibold">{children}</h2>
    ),
    h3: ({ children }: { children?: React.ReactNode }) => (
        <h3 className="mt-2 mb-1 text-sm font-semibold">{children}</h3>
    ),
    code: ({ children }: { children?: React.ReactNode }) => (
        <code className="rounded bg-foreground/10 px-1 py-0.5 font-mono text-[0.85em]">
            {children}
        </code>
    ),
    pre: ({ children }: { children?: React.ReactNode }) => (
        <pre className="my-2 overflow-x-auto rounded-md bg-foreground/10 p-3 font-mono text-xs">
            {children}
        </pre>
    ),
    hr: () => <hr className="my-3 border-foreground/15" />,
    blockquote: ({ children }: { children?: React.ReactNode }) => (
        <blockquote className="my-2 border-l-2 border-foreground/30 pl-3 italic opacity-90">
            {children}
        </blockquote>
    ),
};

function ContentBlock({ block }: { block: ChatContentBlock }) {
    if (block.type === 'text') {
        return (
            <div className="text-sm leading-relaxed">
                <Markdown components={markdownComponents}>{block.text}</Markdown>
            </div>
        );
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
                <div className="text-sm leading-relaxed">
                    <Markdown components={markdownComponents}>{text}</Markdown>
                </div>
                <span className="ml-0.5 inline-block h-4 w-0.5 animate-pulse bg-foreground/70" />
            </div>
        </div>
    );
}

export default function ChatMessageThread({
    messages,
    agent,
    isThinking,
    streamingText,
    activityLabel,
}: {
    messages: ChatMessage[];
    agent: Agent;
    isThinking: boolean;
    streamingText: string | null;
    activityLabel?: string | null;
}) {
    const bottomRef = useRef<HTMLDivElement>(null);
    const isStreaming = streamingText !== null;

    useEffect(() => {
        bottomRef.current?.scrollIntoView({ behavior: 'smooth' });
    }, [messages.length, isThinking, streamingText, activityLabel]);

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
                        <div className="space-y-1">
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
                            {activityLabel && (
                                <p className="px-1 text-xs text-muted-foreground">
                                    {agent.name} is {activityLabel}…
                                </p>
                            )}
                        </div>
                    </div>
                )}
            </div>

            <div ref={bottomRef} />
        </div>
    );
}
