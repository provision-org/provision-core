import {
    AlertCircle,
    BookOpen,
    Brain,
    Check,
    Edit3,
    FileText,
    Loader2,
    Save,
    X,
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import type { Agent } from '@/types';

type MemoryFile = {
    name: string;
    size: number;
    modified_at: string;
};

type MemoryFileDetail = {
    filename: string;
    content: string;
    frontmatter: Record<string, string> | null;
};

export default function MemoryBrowser({ agent }: { agent: Agent }) {
    const [files, setFiles] = useState<MemoryFile[]>([]);
    const [indexContent, setIndexContent] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);
    const [selectedFile, setSelectedFile] = useState<string | null>(null);
    const [fileDetail, setFileDetail] = useState<MemoryFileDetail | null>(null);
    const [fileLoading, setFileLoading] = useState(false);
    const [editing, setEditing] = useState(false);
    const [editContent, setEditContent] = useState('');
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [saveSuccess, setSaveSuccess] = useState(false);

    const isDeployable = !!(agent.server_id && agent.status !== 'deploying');

    const fetchFiles = useCallback(async () => {
        setLoading(true);
        setError(null);
        try {
            const res = await fetch(`/agents/${agent.id}/memory`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            if (!res.ok) {
                throw new Error('Failed to load memory files.');
            }
            const data = await res.json();
            setFiles(data.files ?? []);
            setIndexContent(data.index ?? null);
        } catch (err) {
            setError(
                err instanceof Error
                    ? err.message
                    : 'Failed to load memory files.',
            );
        } finally {
            setLoading(false);
        }
    }, [agent.id]);

    const fetchFile = useCallback(
        async (filename: string) => {
            setFileLoading(true);
            setError(null);
            setEditing(false);
            try {
                const res = await fetch(
                    `/agents/${agent.id}/memory/${encodeURIComponent(filename)}`,
                    {
                        headers: { Accept: 'application/json' },
                        credentials: 'same-origin',
                    },
                );
                if (!res.ok) {
                    throw new Error('Failed to load file.');
                }
                const data: MemoryFileDetail = await res.json();
                setFileDetail(data);
                setSelectedFile(filename);
            } catch (err) {
                setError(
                    err instanceof Error
                        ? err.message
                        : 'Failed to load file.',
                );
            } finally {
                setFileLoading(false);
            }
        },
        [agent.id],
    );

    async function handleSave() {
        if (!selectedFile || !fileDetail) return;
        setSaving(true);
        setError(null);
        try {
            const csrfToken = decodeURIComponent(
                document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '',
            );
            const res = await fetch(
                `/agents/${agent.id}/memory/${encodeURIComponent(selectedFile)}`,
                {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-XSRF-TOKEN': csrfToken,
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({ content: editContent }),
                },
            );
            if (!res.ok) {
                throw new Error('Failed to save file.');
            }
            setFileDetail({ ...fileDetail, content: editContent });
            setEditing(false);
            setSaveSuccess(true);
            setTimeout(() => setSaveSuccess(false), 2000);
        } catch (err) {
            setError(
                err instanceof Error ? err.message : 'Failed to save file.',
            );
        } finally {
            setSaving(false);
        }
    }

    useEffect(() => {
        if (isDeployable) {
            fetchFiles();
        }
    }, [isDeployable, fetchFiles]);

    if (!isDeployable) {
        return (
            <div className="rounded-lg border border-dashed p-8 text-center">
                <Brain className="mx-auto size-8 text-muted-foreground/50" />
                <p className="mt-3 text-sm text-muted-foreground">
                    Deploy this agent to access its memory.
                </p>
            </div>
        );
    }

    if (loading) {
        return (
            <div className="flex items-center justify-center py-20">
                <Loader2 className="size-5 animate-spin text-muted-foreground" />
                <span className="ml-2 text-sm text-muted-foreground">
                    Loading memory files...
                </span>
            </div>
        );
    }

    const hasFiles = files.length > 0 || indexContent !== null;

    if (!hasFiles && !error) {
        return (
            <div className="rounded-lg border border-dashed p-8 text-center">
                <Brain className="mx-auto size-8 text-muted-foreground/50" />
                <h3 className="mt-3 text-sm font-medium">
                    No memory files yet
                </h3>
                <p className="mt-1 text-sm text-muted-foreground">
                    Memory files will appear here once the agent starts building
                    context.
                </p>
            </div>
        );
    }

    return (
        <div className="space-y-4">
            {error && (
                <div className="flex items-center gap-2 rounded-lg border border-destructive/30 bg-destructive/5 px-4 py-3 text-sm text-destructive">
                    <AlertCircle className="size-4 shrink-0" />
                    {error}
                </div>
            )}

            <div className="flex gap-4 rounded-lg border">
                {/* Left panel: file list */}
                <div className="w-56 shrink-0 border-r">
                    <div className="border-b px-3 py-2">
                        <h4 className="text-xs font-medium text-muted-foreground uppercase tracking-wide">
                            Memory Files
                        </h4>
                    </div>
                    <div className="max-h-[500px] overflow-y-auto">
                        {/* MEMORY.md index */}
                        {indexContent !== null && (
                            <button
                                type="button"
                                onClick={() => fetchFile('MEMORY.md')}
                                className={cn(
                                    'flex w-full items-center gap-2 border-b px-3 py-2 text-left text-sm transition-colors hover:bg-muted/50',
                                    selectedFile === 'MEMORY.md' &&
                                        'bg-muted font-medium',
                                )}
                            >
                                <BookOpen className="size-3.5 shrink-0 text-muted-foreground" />
                                <span className="truncate">
                                    MEMORY.md
                                </span>
                                <Badge
                                    variant="secondary"
                                    className="ml-auto shrink-0 text-[10px] px-1 py-0"
                                >
                                    Index
                                </Badge>
                            </button>
                        )}

                        {/* Memory files */}
                        {files.map((file) => (
                            <button
                                key={file.name}
                                type="button"
                                onClick={() => fetchFile(file.name)}
                                className={cn(
                                    'flex w-full items-center gap-2 px-3 py-2 text-left text-sm transition-colors hover:bg-muted/50',
                                    selectedFile === file.name &&
                                        'bg-muted font-medium',
                                )}
                            >
                                <FileText className="size-3.5 shrink-0 text-muted-foreground" />
                                <span className="truncate">{file.name}</span>
                            </button>
                        ))}
                    </div>
                </div>

                {/* Right panel: content viewer/editor */}
                <div className="min-w-0 flex-1">
                    {fileLoading ? (
                        <div className="flex items-center justify-center py-20">
                            <Loader2 className="size-4 animate-spin text-muted-foreground" />
                            <span className="ml-2 text-sm text-muted-foreground">
                                Loading...
                            </span>
                        </div>
                    ) : fileDetail ? (
                        <div className="flex h-full flex-col">
                            {/* File header */}
                            <div className="flex items-center justify-between border-b px-4 py-2">
                                <div className="flex items-center gap-2 min-w-0">
                                    <span className="truncate text-sm font-medium">
                                        {fileDetail.filename}
                                    </span>
                                    {fileDetail.frontmatter && (
                                        <div className="flex items-center gap-1.5 shrink-0">
                                            {fileDetail.frontmatter.type && (
                                                <Badge
                                                    variant="secondary"
                                                    className="text-[10px] px-1.5 py-0"
                                                >
                                                    {fileDetail.frontmatter.type}
                                                </Badge>
                                            )}
                                            {fileDetail.frontmatter.name &&
                                                fileDetail.frontmatter.name !==
                                                    fileDetail.filename && (
                                                    <span className="text-xs text-muted-foreground">
                                                        {fileDetail.frontmatter.name}
                                                    </span>
                                                )}
                                        </div>
                                    )}
                                </div>
                                <div className="flex items-center gap-1.5 shrink-0">
                                    {saveSuccess && (
                                        <span className="flex items-center gap-1 text-xs text-green-600">
                                            <Check className="size-3" />
                                            Saved
                                        </span>
                                    )}
                                    {editing ? (
                                        <>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                className="h-7 text-xs"
                                                onClick={() => {
                                                    setEditing(false);
                                                    setEditContent('');
                                                }}
                                                disabled={saving}
                                            >
                                                <X className="mr-1 size-3" />
                                                Cancel
                                            </Button>
                                            <Button
                                                size="sm"
                                                className="h-7 text-xs"
                                                onClick={handleSave}
                                                disabled={saving}
                                            >
                                                {saving ? (
                                                    <Loader2 className="mr-1 size-3 animate-spin" />
                                                ) : (
                                                    <Save className="mr-1 size-3" />
                                                )}
                                                {saving
                                                    ? 'Saving...'
                                                    : 'Save'}
                                            </Button>
                                        </>
                                    ) : (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            className="h-7 text-xs"
                                            onClick={() => {
                                                setEditContent(
                                                    fileDetail.content,
                                                );
                                                setEditing(true);
                                            }}
                                        >
                                            <Edit3 className="mr-1 size-3" />
                                            Edit
                                        </Button>
                                    )}
                                </div>
                            </div>

                            {/* Frontmatter description */}
                            {fileDetail.frontmatter?.description &&
                                !editing && (
                                    <div className="border-b bg-muted/30 px-4 py-2">
                                        <p className="text-xs text-muted-foreground">
                                            {fileDetail.frontmatter.description}
                                        </p>
                                    </div>
                                )}

                            {/* Content */}
                            <div className="min-h-0 flex-1 overflow-y-auto">
                                {editing ? (
                                    <Textarea
                                        value={editContent}
                                        onChange={(e) =>
                                            setEditContent(e.target.value)
                                        }
                                        className="min-h-[400px] w-full resize-none rounded-none border-0 font-mono text-sm shadow-none focus-visible:ring-0"
                                        placeholder="Enter memory content..."
                                    />
                                ) : (
                                    <pre className="max-h-[500px] overflow-y-auto whitespace-pre-wrap break-words p-4 font-mono text-sm leading-relaxed text-foreground/90">
                                        {fileDetail.content}
                                    </pre>
                                )}
                            </div>
                        </div>
                    ) : (
                        <div className="flex flex-col items-center justify-center py-20 text-center">
                            <Brain className="size-6 text-muted-foreground/40" />
                            <p className="mt-2 text-sm text-muted-foreground">
                                Select a file to view its contents
                            </p>
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}
