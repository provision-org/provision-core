import { Head } from '@inertiajs/react';
import {
    ChevronRight,
    Download,
    FileText,
    FolderOpen,
    FolderPlus,
    Loader2,
    MoreHorizontal,
    RefreshCw,
    Trash2,
    Upload,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';

type WorkspaceFile = {
    name: string;
    path: string;
    type: 'file' | 'directory';
    size: number;
    modified_at: string;
};

function formatBytes(bytes: number): string {
    if (bytes === 0) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(1024));
    return `${(bytes / Math.pow(1024, i)).toFixed(i > 0 ? 1 : 0)} ${units[i]}`;
}

function relativeTime(dateStr: string): string {
    const now = Date.now();
    const then = new Date(dateStr).getTime();
    const diff = Math.floor((now - then) / 1000);
    if (diff < 60) return 'just now';
    if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
    if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
    return `${Math.floor(diff / 86400)}d ago`;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Company', href: '/company/tasks' },
    { title: 'Shared Workspace', href: '/company/workspace' },
];

export default function SharedWorkspaceIndex({
    hasServer,
}: {
    hasServer: boolean;
}) {
    const [files, setFiles] = useState<WorkspaceFile[]>([]);
    const [currentPath, setCurrentPath] = useState('');
    const [loading, setLoading] = useState(false);
    const [uploading, setUploading] = useState(false);
    const [usage, setUsage] = useState(0);
    const [showNewFolder, setShowNewFolder] = useState(false);
    const [newFolderName, setNewFolderName] = useState('');
    const [dragActive, setDragActive] = useState(false);
    const fileInputRef = useRef<HTMLInputElement>(null);

    const fetchFiles = useCallback(
        async (options?: { showLoader?: boolean; fresh?: boolean }) => {
            const { showLoader = true, fresh = false } = options ?? {};
            if (showLoader) setLoading(true);
            try {
                const url = `/company/workspace${fresh ? '?fresh=1' : ''}`;
                const res = await fetch(url, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });
                const data = await res.json();
                setFiles(data.files ?? []);
                setUsage(data.usage ?? 0);
            } finally {
                if (showLoader) setLoading(false);
            }
        },
        [],
    );

    useEffect(() => {
        if (hasServer) {
            fetchFiles({ showLoader: true });
        }
    }, [hasServer, fetchFiles]);

    if (!hasServer) {
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title="Shared Workspace" />
                <div className="mx-auto w-full max-w-4xl px-6 py-8">
                    <Heading
                        title="Shared Workspace"
                        description="Files shared across all agents in your team."
                    />
                    <div className="mt-6 rounded-lg border border-dashed p-8 text-center">
                        <FileText className="mx-auto size-8 text-muted-foreground/50" />
                        <p className="mt-3 text-sm text-muted-foreground">
                            Deploy a server to access the shared workspace.
                        </p>
                    </div>
                </div>
            </AppLayout>
        );
    }

    const currentFiles = files.filter((f) => {
        if (!currentPath) {
            return !f.path.includes('/');
        }
        const prefix = currentPath + '/';
        if (!f.path.startsWith(prefix)) return false;
        const rest = f.path.slice(prefix.length);
        return !rest.includes('/');
    });

    const sortedFiles = [...currentFiles].sort((a, b) => {
        if (a.type !== b.type) return a.type === 'directory' ? -1 : 1;
        return a.name.localeCompare(b.name);
    });

    const breadcrumbSegments = currentPath ? currentPath.split('/') : [];

    async function handleUpload(fileList: FileList | null) {
        if (!fileList || fileList.length === 0) return;
        setUploading(true);
        try {
            const formData = new FormData();
            for (let i = 0; i < fileList.length; i++) {
                formData.append('files[]', fileList[i]);
            }
            if (currentPath) formData.append('path', currentPath);

            const csrfToken = decodeURIComponent(
                document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '',
            );

            await fetch('/company/workspace/upload', {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': csrfToken,
                },
                credentials: 'same-origin',
                body: formData,
            });

            await fetchFiles({ showLoader: false, fresh: true });
        } finally {
            setUploading(false);
            if (fileInputRef.current) fileInputRef.current.value = '';
        }
    }

    async function handleCreateFolder() {
        if (!newFolderName.trim()) return;
        const csrfToken = decodeURIComponent(
            document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '',
        );
        await fetch('/company/workspace/folder', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': csrfToken,
            },
            credentials: 'same-origin',
            body: JSON.stringify({ name: newFolderName, path: currentPath }),
        });
        setNewFolderName('');
        setShowNewFolder(false);
        await fetchFiles({ showLoader: false, fresh: true });
    }

    async function handleDelete(path: string) {
        if (!confirm('Are you sure you want to delete this?')) return;
        const csrfToken = decodeURIComponent(
            document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '',
        );
        await fetch('/company/workspace', {
            method: 'DELETE',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': csrfToken,
            },
            credentials: 'same-origin',
            body: JSON.stringify({ path }),
        });
        await fetchFiles({ showLoader: false, fresh: true });
    }

    function handleDownload(path: string) {
        window.open(
            `/company/workspace/download?path=${encodeURIComponent(path)}`,
            '_blank',
        );
    }

    function handleDragOver(e: React.DragEvent) {
        e.preventDefault();
        setDragActive(true);
    }

    function handleDragLeave(e: React.DragEvent) {
        e.preventDefault();
        setDragActive(false);
    }

    function handleDrop(e: React.DragEvent) {
        e.preventDefault();
        setDragActive(false);
        handleUpload(e.dataTransfer.files);
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Shared Workspace" />
            <div className="mx-auto w-full max-w-4xl px-6 py-8">
                <Heading
                    title="Shared Workspace"
                    description="Files shared across all agents in your team."
                />

                <div className="mt-6 space-y-4">
                    {/* Usage */}
                    <div className="rounded-lg border px-4 py-3">
                        <div className="flex items-center justify-between text-sm">
                            <span className="text-muted-foreground">
                                {formatBytes(usage)} used
                            </span>
                        </div>
                    </div>

                    {/* Breadcrumbs + actions */}
                    <div className="flex items-center justify-between">
                        <div className="flex items-center gap-1 text-sm">
                            <button
                                type="button"
                                onClick={() => setCurrentPath('')}
                                className={cn(
                                    'hover:text-foreground',
                                    currentPath
                                        ? 'text-muted-foreground'
                                        : 'font-medium text-foreground',
                                )}
                            >
                                shared
                            </button>
                            {breadcrumbSegments.map((segment, i) => {
                                const path = breadcrumbSegments
                                    .slice(0, i + 1)
                                    .join('/');
                                const isLast =
                                    i === breadcrumbSegments.length - 1;
                                return (
                                    <span
                                        key={path}
                                        className="flex items-center gap-1"
                                    >
                                        <ChevronRight className="size-3 text-muted-foreground" />
                                        <button
                                            type="button"
                                            onClick={() => setCurrentPath(path)}
                                            className={cn(
                                                'hover:text-foreground',
                                                isLast
                                                    ? 'font-medium text-foreground'
                                                    : 'text-muted-foreground',
                                            )}
                                        >
                                            {segment}
                                        </button>
                                    </span>
                                );
                            })}
                        </div>
                        <div className="flex items-center gap-2">
                            <Button
                                variant="ghost"
                                size="icon"
                                className="size-7"
                                onClick={() => fetchFiles({ fresh: true })}
                                disabled={loading}
                            >
                                <RefreshCw
                                    className={cn(
                                        'size-3.5',
                                        loading && 'animate-spin',
                                    )}
                                />
                                <span className="sr-only">Refresh</span>
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => setShowNewFolder(!showNewFolder)}
                                className="gap-1.5"
                            >
                                <FolderPlus className="size-3.5" />
                                Folder
                            </Button>
                            <Button
                                size="sm"
                                onClick={() => fileInputRef.current?.click()}
                                disabled={uploading}
                                className="gap-1.5"
                            >
                                {uploading ? (
                                    <Loader2 className="size-3.5 animate-spin" />
                                ) : (
                                    <Upload className="size-3.5" />
                                )}
                                Upload
                            </Button>
                            <input
                                ref={fileInputRef}
                                type="file"
                                multiple
                                className="hidden"
                                onChange={(e) => handleUpload(e.target.files)}
                            />
                        </div>
                    </div>

                    {/* New folder inline form */}
                    {showNewFolder && (
                        <div className="flex items-center gap-2">
                            <Input
                                value={newFolderName}
                                onChange={(e) =>
                                    setNewFolderName(e.target.value)
                                }
                                placeholder="Folder name"
                                className="max-w-xs"
                                onKeyDown={(e) =>
                                    e.key === 'Enter' && handleCreateFolder()
                                }
                                autoFocus
                            />
                            <Button
                                size="sm"
                                onClick={handleCreateFolder}
                                disabled={!newFolderName.trim()}
                            >
                                Create
                            </Button>
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => {
                                    setShowNewFolder(false);
                                    setNewFolderName('');
                                }}
                            >
                                Cancel
                            </Button>
                        </div>
                    )}

                    {/* File list */}
                    <div
                        className={cn(
                            'rounded-lg border transition-colors',
                            dragActive && 'border-primary bg-primary/5',
                        )}
                        onDragOver={handleDragOver}
                        onDragLeave={handleDragLeave}
                        onDrop={handleDrop}
                    >
                        {loading ? (
                            <div className="flex items-center justify-center py-12">
                                <Loader2 className="size-5 animate-spin text-muted-foreground" />
                            </div>
                        ) : sortedFiles.length === 0 && !currentPath ? (
                            <div className="py-12 text-center">
                                <FolderOpen className="mx-auto size-8 text-muted-foreground/50" />
                                <p className="mt-3 text-sm text-muted-foreground">
                                    Upload files to share across all agents.
                                </p>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    className="mt-3 gap-1.5"
                                    onClick={() =>
                                        fileInputRef.current?.click()
                                    }
                                >
                                    <Upload className="size-3.5" />
                                    Upload Files
                                </Button>
                            </div>
                        ) : (
                            <div className="divide-y">
                                {currentPath && (
                                    <button
                                        type="button"
                                        className="flex w-full items-center gap-3 px-4 py-2.5 text-left text-sm hover:bg-muted/50"
                                        onClick={() => {
                                            const parent = currentPath
                                                .split('/')
                                                .slice(0, -1)
                                                .join('/');
                                            setCurrentPath(parent);
                                        }}
                                    >
                                        <FolderOpen className="size-4 text-muted-foreground" />
                                        <span className="text-muted-foreground">
                                            ..
                                        </span>
                                    </button>
                                )}
                                {sortedFiles.map((file) => (
                                    <div
                                        key={file.path}
                                        className="group flex items-center gap-3 px-4 py-2.5 text-sm"
                                    >
                                        {file.type === 'directory' ? (
                                            <button
                                                type="button"
                                                className="flex flex-1 items-center gap-3 text-left hover:text-foreground"
                                                onClick={() =>
                                                    setCurrentPath(file.path)
                                                }
                                            >
                                                <FolderOpen className="size-4 text-amber-500" />
                                                <span className="flex-1 font-medium">
                                                    {file.name}
                                                </span>
                                            </button>
                                        ) : (
                                            <div className="flex flex-1 items-center gap-3">
                                                <FileText className="size-4 text-muted-foreground" />
                                                <span className="flex-1">
                                                    {file.name}
                                                </span>
                                                <span className="text-xs text-muted-foreground">
                                                    {formatBytes(file.size)}
                                                </span>
                                            </div>
                                        )}
                                        <span className="text-xs text-muted-foreground">
                                            {relativeTime(file.modified_at)}
                                        </span>
                                        <DropdownMenu>
                                            <DropdownMenuTrigger asChild>
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    className="size-7 p-0 opacity-0 group-hover:opacity-100"
                                                >
                                                    <MoreHorizontal className="size-4" />
                                                </Button>
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent align="end">
                                                {file.type === 'file' && (
                                                    <DropdownMenuItem
                                                        onClick={() =>
                                                            handleDownload(
                                                                file.path,
                                                            )
                                                        }
                                                    >
                                                        <Download className="mr-2 size-4" />
                                                        Download
                                                    </DropdownMenuItem>
                                                )}
                                                <DropdownMenuItem
                                                    className="text-destructive focus:text-destructive"
                                                    onClick={() =>
                                                        handleDelete(file.path)
                                                    }
                                                >
                                                    <Trash2 className="mr-2 size-4" />
                                                    Delete
                                                </DropdownMenuItem>
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    </div>
                                ))}
                                {sortedFiles.length === 0 && currentPath && (
                                    <div className="py-8 text-center text-sm text-muted-foreground">
                                        This folder is empty.
                                    </div>
                                )}
                            </div>
                        )}

                        {dragActive && (
                            <div className="border-t border-dashed border-primary py-4 text-center text-sm text-primary">
                                Drop files here to upload
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
