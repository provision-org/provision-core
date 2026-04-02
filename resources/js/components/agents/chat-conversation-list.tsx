import { MessageSquarePlus } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { relativeTime } from '@/lib/agents';
import { cn } from '@/lib/utils';
import type { ChatConversation } from '@/types';

export default function ChatConversationList({
    conversations,
    activeId,
    onSelect,
    onNewChat,
}: {
    conversations: ChatConversation[];
    activeId: string | null;
    onSelect: (conversation: ChatConversation) => void;
    onNewChat: () => void;
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
                        {conversations.map((conv) => (
                            <button
                                key={conv.id}
                                onClick={() => onSelect(conv)}
                                className={cn(
                                    'w-full rounded-md px-3 py-2.5 text-left text-sm transition-colors',
                                    activeId === conv.id
                                        ? 'bg-accent text-accent-foreground'
                                        : 'hover:bg-accent/50',
                                )}
                            >
                                <div className="truncate font-medium">
                                    {conv.title || 'New conversation'}
                                </div>
                                {conv.last_message_at && (
                                    <div className="mt-0.5 text-xs text-muted-foreground">
                                        {relativeTime(conv.last_message_at)}
                                    </div>
                                )}
                            </button>
                        ))}
                    </div>
                )}
            </div>
        </div>
    );
}
