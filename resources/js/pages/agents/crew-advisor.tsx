import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, Loader2, Send, Sparkles } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';

type Message = {
    role: 'user' | 'assistant';
    content: string;
};

type AgentRecommendation = {
    name: string;
    role: string;
    job_description: string;
    why: string;
};

function csrfToken(): string {
    return decodeURIComponent(
        document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '',
    );
}

function parseRecommendations(content: string): AgentRecommendation[] {
    const match = content.match(
        /<crew-recommendations>([\s\S]*?)<\/crew-recommendations>/,
    );
    if (!match) return [];
    try {
        return JSON.parse(match[1]);
    } catch {
        return [];
    }
}

function stripRecommendationTags(content: string): string {
    return content
        .replace(/<crew-recommendations>[\s\S]*?<\/crew-recommendations>/, '')
        .trim();
}

const ROLE_LABELS: Record<string, string> = {
    bdr: 'Sales & Outreach',
    researcher: 'Research',
    customer_support: 'Customer Support',
    content_writer: 'Content',
    executive_assistant: 'Executive Assistant',
    data_analyst: 'Data Analysis',
    project_manager: 'Project Management',
    custom: 'Custom',
};

const ROLE_COLORS: Record<string, string> = {
    bdr: '#6366f1',
    researcher: '#f59e0b',
    customer_support: '#14b8a6',
    content_writer: '#ec4899',
    executive_assistant: '#8b5cf6',
    data_analyst: '#f97316',
    project_manager: '#22c55e',
    custom: '#64748b',
};

export default function CrewAdvisor({
    companyName,
    hasCompanyContext,
}: {
    companyName?: string;
    hasCompanyContext?: boolean;
}) {
    const [messages, setMessages] = useState<Message[]>([]);
    const [input, setInput] = useState('');
    const [isStreaming, setIsStreaming] = useState(false);
    const [recommendations, setRecommendations] = useState<
        AgentRecommendation[]
    >([]);
    const [creatingAgent, setCreatingAgent] = useState<string | null>(null);
    const messagesEndRef = useRef<HTMLDivElement>(null);
    const inputRef = useRef<HTMLTextAreaElement>(null);
    const pendingSendRef = useRef(false);

    // Auto-scroll to bottom
    useEffect(() => {
        messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
    }, [messages]);

    // Focus input on mount
    useEffect(() => {
        inputRef.current?.focus();
    }, []);

    const sendMessage = useCallback(
        async (overrideInput?: string) => {
            const trimmed = (overrideInput ?? input).trim();
            if (!trimmed || isStreaming) return;

            const userMessage: Message = { role: 'user', content: trimmed };
            const newMessages = [...messages, userMessage];
            setMessages(newMessages);
            setInput('');
            setIsStreaming(true);

            // Add placeholder assistant message
            const assistantMessage: Message = {
                role: 'assistant',
                content: '',
            };
            setMessages([...newMessages, assistantMessage]);

            try {
                const response = await fetch('/crew-advisor/chat', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-XSRF-TOKEN': csrfToken(),
                        Accept: 'text/event-stream',
                    },
                    body: JSON.stringify({
                        message: trimmed,
                        history: newMessages.map((m) => ({
                            role: m.role,
                            content: m.content,
                        })),
                    }),
                });

                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }

                const reader = response.body?.getReader();
                const decoder = new TextDecoder();
                let fullContent = '';

                if (reader) {
                    let buffer = '';

                    while (true) {
                        const { done, value } = await reader.read();
                        if (done) break;

                        buffer += decoder.decode(value, { stream: true });
                        const lines = buffer.split('\n');
                        // Keep the last potentially incomplete line in the buffer
                        buffer = lines.pop() ?? '';

                        for (const line of lines) {
                            if (line.startsWith('data: ')) {
                                const data = line.slice(6);
                                if (data === '[DONE]') continue;
                                try {
                                    const parsed = JSON.parse(data);
                                    if (parsed.text) {
                                        fullContent += parsed.text;
                                        setMessages((prev) => {
                                            const updated = [...prev];
                                            updated[updated.length - 1] = {
                                                role: 'assistant',
                                                content: fullContent,
                                            };
                                            return updated;
                                        });
                                    }
                                } catch {
                                    // Non-JSON data — append as raw text
                                    fullContent += data;
                                    setMessages((prev) => {
                                        const updated = [...prev];
                                        updated[updated.length - 1] = {
                                            role: 'assistant',
                                            content: fullContent,
                                        };
                                        return updated;
                                    });
                                }
                            }
                        }
                    }
                }

                // Check for recommendations in the final content
                const recs = parseRecommendations(fullContent);
                if (recs.length > 0) {
                    setRecommendations(recs);
                }
            } catch {
                setMessages((prev) => {
                    const updated = [...prev];
                    updated[updated.length - 1] = {
                        role: 'assistant',
                        content:
                            'Sorry, something went wrong. Please try again.',
                    };
                    return updated;
                });
            } finally {
                setIsStreaming(false);
            }
        },
        [input, isStreaming, messages],
    );

    // Auto-send when input is set via quick prompt
    useEffect(() => {
        if (pendingSendRef.current && input.trim()) {
            pendingSendRef.current = false;
            sendMessage(input);
        }
    }, [input, sendMessage]);

    function handleQuickPrompt(prompt: string) {
        if (isStreaming) return;
        pendingSendRef.current = true;
        setInput(prompt);
    }

    function handleCreateAgent(rec: AgentRecommendation) {
        setCreatingAgent(rec.name);
        router.visit('/agents/create', {
            data: {
                prefill_name: rec.name,
                prefill_role: rec.role,
                prefill_job_description: rec.job_description,
            },
        });
    }

    // Auto-grow textarea
    function handleTextareaChange(e: React.ChangeEvent<HTMLTextAreaElement>) {
        setInput(e.target.value);
        e.target.style.height = 'auto';
        e.target.style.height = `${Math.min(e.target.scrollHeight, 120)}px`;
    }

    return (
        <div className="flex min-h-svh flex-col bg-background">
            <Head title="Crew Advisor" />

            {/* Top bar */}
            <div className="border-b px-6 py-4">
                <div className="mx-auto flex max-w-3xl items-center justify-between">
                    <Link
                        href="/agents"
                        className="inline-flex items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
                    >
                        <ArrowLeft className="size-3.5" />
                        Agents
                    </Link>
                    <div className="flex items-center gap-2">
                        <Sparkles className="size-4 text-primary" />
                        <span className="text-sm font-medium">
                            Crew Advisor
                        </span>
                    </div>
                </div>
            </div>

            {/* Chat area */}
            <div className="flex flex-1 flex-col">
                <div className="mx-auto flex w-full max-w-3xl flex-1 flex-col px-6">
                    {/* Messages */}
                    <div className="flex-1 space-y-6 py-8">
                        {messages.length === 0 && (
                            <div className="flex flex-col items-center justify-center pt-20 text-center">
                                <div className="mb-4 flex size-16 items-center justify-center rounded-2xl bg-primary/10">
                                    <Sparkles className="size-8 text-primary" />
                                </div>
                                <h2 className="text-xl font-bold">
                                    Build your crew
                                </h2>
                                <p className="mt-2 max-w-md text-sm text-muted-foreground">
                                    Tell me about your business and I'll
                                    recommend the perfect AI agents for your
                                    team.
                                </p>
                                {/* Quick start prompts */}
                                <div className="mt-8 flex flex-wrap justify-center gap-2">
                                    {[
                                        hasCompanyContext
                                            ? `What agents would work best for ${companyName || 'my business'}?`
                                            : 'I run a SaaS company and need help with outbound sales',
                                        "We're a small team drowning in customer support",
                                        'I need agents to help with research and competitive analysis',
                                    ].map((prompt) => (
                                        <button
                                            key={prompt}
                                            type="button"
                                            onClick={() =>
                                                handleQuickPrompt(prompt)
                                            }
                                            className="rounded-lg border border-border px-4 py-2.5 text-left text-sm text-muted-foreground transition-colors hover:border-foreground/20 hover:text-foreground"
                                        >
                                            {prompt}
                                        </button>
                                    ))}
                                </div>
                            </div>
                        )}

                        {messages.map((msg, i) => (
                            <div
                                key={i}
                                className={`flex gap-3 ${msg.role === 'user' ? 'justify-end' : ''}`}
                            >
                                {msg.role === 'assistant' && (
                                    <div className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary/10">
                                        <Sparkles className="size-4 text-primary" />
                                    </div>
                                )}
                                <div
                                    className={`max-w-[80%] rounded-2xl px-4 py-3 text-sm leading-relaxed ${
                                        msg.role === 'user'
                                            ? 'bg-primary text-primary-foreground'
                                            : 'bg-muted'
                                    }`}
                                >
                                    {msg.role === 'assistant'
                                        ? stripRecommendationTags(msg.content)
                                        : msg.content}
                                    {msg.role === 'assistant' &&
                                        !msg.content &&
                                        isStreaming && (
                                            <span className="inline-flex gap-1">
                                                <span
                                                    className="size-1.5 animate-bounce rounded-full bg-muted-foreground/40"
                                                    style={{
                                                        animationDelay: '0ms',
                                                    }}
                                                />
                                                <span
                                                    className="size-1.5 animate-bounce rounded-full bg-muted-foreground/40"
                                                    style={{
                                                        animationDelay: '150ms',
                                                    }}
                                                />
                                                <span
                                                    className="size-1.5 animate-bounce rounded-full bg-muted-foreground/40"
                                                    style={{
                                                        animationDelay: '300ms',
                                                    }}
                                                />
                                            </span>
                                        )}
                                </div>
                            </div>
                        ))}

                        {/* Agent recommendation cards */}
                        {recommendations.length > 0 && (
                            <div className="space-y-4 pt-4">
                                <h3 className="text-sm font-semibold tracking-wider text-muted-foreground uppercase">
                                    Recommended crew
                                </h3>
                                <div className="grid gap-3 sm:grid-cols-2">
                                    {recommendations.map((rec) => (
                                        <div
                                            key={rec.name}
                                            className="rounded-xl border border-border bg-card p-4 transition-colors hover:border-foreground/10"
                                        >
                                            <div className="flex items-start justify-between gap-3">
                                                <div className="flex-1">
                                                    <div className="flex items-center gap-2">
                                                        <h4 className="font-semibold">
                                                            {rec.name}
                                                        </h4>
                                                        <span
                                                            className="rounded-full px-2 py-0.5 text-[10px] font-semibold"
                                                            style={{
                                                                backgroundColor: `${ROLE_COLORS[rec.role] || '#64748b'}15`,
                                                                color:
                                                                    ROLE_COLORS[
                                                                        rec.role
                                                                    ] ||
                                                                    '#64748b',
                                                            }}
                                                        >
                                                            {ROLE_LABELS[
                                                                rec.role
                                                            ] || rec.role}
                                                        </span>
                                                    </div>
                                                    <p className="mt-1.5 text-xs leading-relaxed text-muted-foreground">
                                                        {rec.job_description}
                                                    </p>
                                                </div>
                                            </div>
                                            <div className="mt-3 flex items-center justify-between">
                                                <p className="text-[11px] text-muted-foreground/70 italic">
                                                    {rec.why}
                                                </p>
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    className="ml-3 shrink-0"
                                                    onClick={() =>
                                                        handleCreateAgent(rec)
                                                    }
                                                    disabled={
                                                        creatingAgent ===
                                                        rec.name
                                                    }
                                                >
                                                    {creatingAgent ===
                                                    rec.name ? (
                                                        <Loader2 className="size-3 animate-spin" />
                                                    ) : (
                                                        'Create'
                                                    )}
                                                </Button>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        )}

                        <div ref={messagesEndRef} />
                    </div>
                </div>

                {/* Input area - fixed at bottom */}
                <div className="border-t bg-background px-6 py-4">
                    <div className="mx-auto flex max-w-3xl items-end gap-3">
                        <textarea
                            ref={inputRef}
                            value={input}
                            onChange={handleTextareaChange}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter' && !e.shiftKey) {
                                    e.preventDefault();
                                    sendMessage();
                                }
                            }}
                            placeholder="Tell me about your business..."
                            rows={1}
                            className="flex-1 resize-none rounded-xl border border-input bg-transparent px-4 py-3 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                            style={{
                                minHeight: '48px',
                                maxHeight: '120px',
                            }}
                        />
                        <Button
                            onClick={() => sendMessage()}
                            disabled={!input.trim() || isStreaming}
                            size="icon"
                            className="size-12 shrink-0 rounded-xl"
                        >
                            {isStreaming ? (
                                <Loader2 className="size-4 animate-spin" />
                            ) : (
                                <Send className="size-4" />
                            )}
                        </Button>
                    </div>
                </div>
            </div>
        </div>
    );
}
