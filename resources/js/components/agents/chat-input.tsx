import { Paperclip, Send, X } from 'lucide-react';
import { useCallback, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

export default function ChatInput({
    onSend,
    disabled,
}: {
    onSend: (content: string, files: File[]) => void;
    disabled: boolean;
}) {
    const [content, setContent] = useState('');
    const [files, setFiles] = useState<File[]>([]);
    const textareaRef = useRef<HTMLTextAreaElement>(null);
    const fileInputRef = useRef<HTMLInputElement>(null);

    const handleSubmit = useCallback(() => {
        const trimmed = content.trim();
        if (!trimmed && files.length === 0) return;
        if (disabled) return;

        onSend(trimmed, files);
        setContent('');
        setFiles([]);

        // Reset textarea height
        if (textareaRef.current) {
            textareaRef.current.style.height = 'auto';
        }
    }, [content, files, disabled, onSend]);

    const handleKeyDown = (e: React.KeyboardEvent<HTMLTextAreaElement>) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            handleSubmit();
        }
    };

    const handleInput = (e: React.ChangeEvent<HTMLTextAreaElement>) => {
        setContent(e.target.value);

        // Auto-resize textarea
        const textarea = e.target;
        textarea.style.height = 'auto';
        textarea.style.height = Math.min(textarea.scrollHeight, 200) + 'px';
    };

    const handleFileSelect = (e: React.ChangeEvent<HTMLInputElement>) => {
        if (e.target.files) {
            const newFiles = Array.from(e.target.files).slice(
                0,
                5 - files.length,
            );
            setFiles((prev) => [...prev, ...newFiles].slice(0, 5));
        }
        // Reset input so the same file can be selected again
        e.target.value = '';
    };

    const removeFile = (index: number) => {
        setFiles((prev) => prev.filter((_, i) => i !== index));
    };

    const handleDrop = useCallback(
        (e: React.DragEvent) => {
            e.preventDefault();
            if (e.dataTransfer.files) {
                const newFiles = Array.from(e.dataTransfer.files).slice(
                    0,
                    5 - files.length,
                );
                setFiles((prev) => [...prev, ...newFiles].slice(0, 5));
            }
        },
        [files.length],
    );

    return (
        <div
            className="shrink-0 border-t p-4"
            onDragOver={(e) => e.preventDefault()}
            onDrop={handleDrop}
        >
            {files.length > 0 && (
                <div className="mb-2 flex flex-wrap gap-2">
                    {files.map((file, i) => (
                        <div
                            key={i}
                            className="flex items-center gap-1.5 rounded-md border bg-muted px-2.5 py-1 text-xs"
                        >
                            <span className="max-w-32 truncate">
                                {file.name}
                            </span>
                            <button
                                type="button"
                                onClick={() => removeFile(i)}
                                className="text-muted-foreground hover:text-foreground"
                            >
                                <X className="size-3" />
                            </button>
                        </div>
                    ))}
                </div>
            )}

            <div className="flex items-end gap-2">
                <div className="relative flex-1">
                    <textarea
                        ref={textareaRef}
                        value={content}
                        onChange={handleInput}
                        onKeyDown={handleKeyDown}
                        placeholder="Type a message..."
                        disabled={disabled}
                        rows={1}
                        className={cn(
                            'w-full resize-none rounded-xl border bg-background px-4 py-3 pr-10 text-sm',
                            'placeholder:text-muted-foreground',
                            'focus:ring-2 focus:ring-ring/50 focus:outline-none',
                            'disabled:cursor-not-allowed disabled:opacity-50',
                        )}
                    />
                    <button
                        type="button"
                        onClick={() => fileInputRef.current?.click()}
                        disabled={disabled || files.length >= 5}
                        className="absolute right-3 bottom-3 text-muted-foreground hover:text-foreground disabled:opacity-50"
                    >
                        <Paperclip className="size-4" />
                    </button>
                    <input
                        ref={fileInputRef}
                        type="file"
                        multiple
                        accept="image/*,.pdf,.txt,.csv,.json,.md"
                        className="hidden"
                        onChange={handleFileSelect}
                    />
                </div>

                <Button
                    size="icon"
                    onClick={handleSubmit}
                    disabled={
                        disabled || (!content.trim() && files.length === 0)
                    }
                    className="size-11 shrink-0 rounded-xl"
                >
                    <Send className="size-4" />
                </Button>
            </div>
        </div>
    );
}
