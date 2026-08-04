import {
    Download,
    LoaderCircle,
    LockKeyhole,
    MessageSquarePlus,
    RefreshCw,
    Server,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { relativeTime } from '@/lib/agents';
import { cn } from '@/lib/utils';
import type { ChatConversation, ChatServerSession } from '@/types';

function sourceLabel(conversation: ChatConversation): string | null {
    if (conversation.source !== 'openclaw') return null;

    if (conversation.source_channel) {
        return conversation.source_channel.replaceAll('-', ' ');
    }

    return 'Server';
}

export default function ChatConversationList({
    conversations,
    activeId,
    onSelect,
    onNewChat,
    serverSessions = [],
    canImportServerSessions = false,
    isRefreshingServerSessions = false,
    importingServerSessionId = null,
    serverSessionsError = null,
    onRefreshServerSessions,
    onImportServerSession,
}: {
    conversations: ChatConversation[];
    activeId: string | null;
    onSelect: (conversation: ChatConversation) => void;
    onNewChat: () => void;
    serverSessions?: ChatServerSession[];
    canImportServerSessions?: boolean;
    isRefreshingServerSessions?: boolean;
    importingServerSessionId?: string | null;
    serverSessionsError?: string | null;
    onRefreshServerSessions?: () => void;
    onImportServerSession?: (session: ChatServerSession) => void;
}) {
    return (
        <div className="flex h-full flex-col">
            <div className="shrink-0 border-b p-3">
                <Button
                    variant="outline"
                    className="w-full justify-start gap-2"
                    onClick={onNewChat}
                >
                    <MessageSquarePlus className="size-4" />
                    New Chat
                </Button>
            </div>

            <div className="flex-1 overflow-y-auto p-2">
                {conversations.length === 0 ? (
                    <div className="px-3 py-8 text-center text-sm text-muted-foreground">
                        No conversations yet
                    </div>
                ) : (
                    <div className="space-y-0.5">
                        {conversations.map((conversation) => {
                            const importedSource = sourceLabel(conversation);

                            return (
                                <button
                                    key={conversation.id}
                                    onClick={() => onSelect(conversation)}
                                    className={cn(
                                        'w-full rounded-md px-3 py-2.5 text-left text-sm transition-colors',
                                        activeId === conversation.id
                                            ? 'bg-accent text-accent-foreground'
                                            : 'hover:bg-accent/50',
                                    )}
                                >
                                    <div className="flex min-w-0 items-center gap-2">
                                        <span className="min-w-0 flex-1 truncate font-medium">
                                            {conversation.title ||
                                                'New conversation'}
                                        </span>
                                        {conversation.is_read_only && (
                                            <LockKeyhole
                                                className="size-3 shrink-0 text-muted-foreground"
                                                aria-label="Read-only conversation"
                                            />
                                        )}
                                    </div>
                                    <div className="mt-1 flex min-w-0 items-center gap-1.5 text-[11px] text-muted-foreground">
                                        {importedSource && (
                                            <span className="max-w-24 truncate rounded border px-1.5 py-0.5 capitalize">
                                                {importedSource}
                                            </span>
                                        )}
                                        {conversation.last_message_at && (
                                            <span className="truncate">
                                                {relativeTime(
                                                    conversation.last_message_at,
                                                )}
                                            </span>
                                        )}
                                    </div>
                                </button>
                            );
                        })}
                    </div>
                )}

                {canImportServerSessions && (
                    <section className="mt-4 border-t pt-3">
                        <div className="flex items-center gap-2 px-2 pb-2">
                            <Server className="size-3.5 text-muted-foreground" />
                            <h2 className="text-xs font-medium">
                                Other server chats
                            </h2>
                            {onRefreshServerSessions && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    className="ml-auto size-7"
                                    onClick={onRefreshServerSessions}
                                    disabled={isRefreshingServerSessions}
                                    aria-label="Refresh server chats"
                                    title="Refresh server chats"
                                >
                                    <RefreshCw
                                        className={cn(
                                            'size-3.5',
                                            isRefreshingServerSessions &&
                                                'animate-spin',
                                        )}
                                    />
                                </Button>
                            )}
                        </div>

                        {serverSessionsError && (
                            <p className="px-2 pb-2 text-xs text-destructive">
                                {serverSessionsError}
                            </p>
                        )}

                        {serverSessions.length === 0 ? (
                            <p className="px-2 py-3 text-xs leading-relaxed text-muted-foreground">
                                No unimported chats found on this agent&apos;s
                                server.
                            </p>
                        ) : (
                            <div className="space-y-1">
                                {serverSessions.map((session) => {
                                    const isImporting =
                                        importingServerSessionId === session.id;

                                    return (
                                        <div
                                            key={session.id}
                                            className="rounded-md border bg-muted/25 p-2.5"
                                        >
                                            <div className="flex min-w-0 items-start gap-2">
                                                <div className="min-w-0 flex-1">
                                                    <p className="truncate text-xs font-medium">
                                                        {session.title ||
                                                            'Server chat'}
                                                    </p>
                                                    <div className="mt-1 flex flex-wrap items-center gap-1 text-[10px] text-muted-foreground">
                                                        <span className="rounded border px-1.5 py-0.5 capitalize">
                                                            {session.channel ??
                                                                session.kind}
                                                        </span>
                                                        {!session.can_send && (
                                                            <span className="inline-flex items-center gap-1 rounded border px-1.5 py-0.5">
                                                                <LockKeyhole className="size-2.5" />
                                                                Read-only
                                                            </span>
                                                        )}
                                                        {session.has_active_run && (
                                                            <span className="inline-flex items-center gap-1 text-emerald-600 dark:text-emerald-400">
                                                                <span className="size-1.5 rounded-full bg-current" />
                                                                Active
                                                            </span>
                                                        )}
                                                    </div>
                                                </div>
                                                {onImportServerSession && (
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        size="sm"
                                                        className="h-7 shrink-0 gap-1 px-2 text-[11px]"
                                                        onClick={() =>
                                                            onImportServerSession(
                                                                session,
                                                            )
                                                        }
                                                        disabled={
                                                            importingServerSessionId !==
                                                            null
                                                        }
                                                        aria-label={`Import ${session.title || 'server chat'}`}
                                                    >
                                                        {isImporting ? (
                                                            <LoaderCircle className="size-3.5 animate-spin" />
                                                        ) : (
                                                            <Download className="size-3.5" />
                                                        )}
                                                        Import
                                                    </Button>
                                                )}
                                            </div>
                                            {session.preview && (
                                                <p className="mt-2 line-clamp-2 text-[11px] leading-relaxed text-muted-foreground">
                                                    {session.preview}
                                                </p>
                                            )}
                                            {session.updated_at && (
                                                <p className="mt-1.5 text-[10px] text-muted-foreground/75">
                                                    {relativeTime(
                                                        session.updated_at,
                                                    )}
                                                </p>
                                            )}
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                    </section>
                )}
            </div>
        </div>
    );
}
