import { router } from '@inertiajs/react';
import { MessageSquare } from 'lucide-react';
import { FormEvent, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import type { TaskNote } from '@/types';

function formatDate(d: string): string {
    return new Date(d).toLocaleString(undefined, {
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

export default function TaskCommentThread({
    notes,
    taskId,
    taskStatus,
}: {
    notes: TaskNote[];
    taskId: string;
    taskStatus: string;
}) {
    const [body, setBody] = useState('');
    const [processing, setProcessing] = useState(false);

    function handleSubmit(e: FormEvent) {
        e.preventDefault();
        if (!body.trim() || processing) {
            return;
        }

        setProcessing(true);
        router.post(
            `/company/tasks/${taskId}/notes`,
            { body },
            {
                preserveScroll: true,
                onSuccess: () => setBody(''),
                onFinish: () => setProcessing(false),
            },
        );
    }

    return (
        <div className="mt-6">
            <h2 className="mb-3 flex items-center gap-2 text-sm font-medium">
                <MessageSquare className="size-4" />
                Comments ({notes.length})
            </h2>

            {notes.length > 0 && (
                <div className="space-y-3">
                    {notes.map((note) => (
                        <div
                            key={note.id}
                            className="rounded-lg border bg-muted/30 p-4"
                        >
                            <div className="mb-2 flex items-center gap-2">
                                <Badge
                                    variant="outline"
                                    className="text-[10px]"
                                >
                                    {note.author_type === 'user'
                                        ? 'User'
                                        : 'Agent'}
                                </Badge>
                                <span className="text-xs text-muted-foreground">
                                    {formatDate(note.created_at)}
                                </span>
                            </div>
                            <p className="text-sm whitespace-pre-wrap">
                                {note.body}
                            </p>
                        </div>
                    ))}
                </div>
            )}

            <form onSubmit={handleSubmit} className="mt-4 space-y-3">
                <Textarea
                    placeholder="Add a comment..."
                    value={body}
                    onChange={(e) => setBody(e.target.value)}
                    rows={3}
                    className="resize-none"
                />

                {taskStatus === 'done' && (
                    <p className="text-xs text-amber-600 dark:text-amber-400">
                        Commenting will reopen this task for revision.
                    </p>
                )}

                <Button
                    type="submit"
                    size="sm"
                    disabled={processing || !body.trim()}
                >
                    {processing ? 'Posting...' : 'Post Comment'}
                </Button>
            </form>
        </div>
    );
}
