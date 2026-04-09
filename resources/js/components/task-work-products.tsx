import { Download, ExternalLink, FileText, Globe, Package } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import type { TaskWorkProduct } from '@/types';

const TYPE_ICONS: Record<string, React.ReactNode> = {
    file: <FileText className="size-4" />,
    url: <Globe className="size-4" />,
};

export default function TaskWorkProducts({
    workProducts,
    taskId,
}: {
    workProducts: TaskWorkProduct[];
    taskId: string;
}) {
    if (workProducts.length === 0) {
        return null;
    }

    return (
        <div className="mt-6">
            <h2 className="mb-3 flex items-center gap-2 text-sm font-medium">
                <Package className="size-4" />
                Work Products ({workProducts.length})
            </h2>
            <div className="space-y-2">
                {workProducts.map((wp) => (
                    <div
                        key={wp.id}
                        className="flex items-start gap-3 rounded-lg border px-4 py-3"
                    >
                        <div className="mt-0.5 text-muted-foreground">
                            {TYPE_ICONS[wp.type] ?? (
                                <FileText className="size-4" />
                            )}
                        </div>
                        <div className="min-w-0 flex-1">
                            <div className="flex items-center gap-2">
                                <span className="text-sm font-medium">
                                    {wp.title}
                                </span>
                                <Badge
                                    variant="outline"
                                    className="text-[10px]"
                                >
                                    {wp.type}
                                </Badge>
                            </div>
                            {wp.summary && (
                                <p className="mt-1 text-sm text-muted-foreground">
                                    {wp.summary}
                                </p>
                            )}
                            {wp.file_path && (
                                <p className="mt-1 font-mono text-xs text-muted-foreground">
                                    {wp.file_path}
                                </p>
                            )}
                        </div>
                        <div className="flex shrink-0 items-center gap-1">
                            {wp.file_path && (
                                <Button variant="ghost" size="sm" asChild>
                                    <a
                                        href={`/company/tasks/${taskId}/work-products/${wp.id}/download`}
                                    >
                                        <Download className="size-3.5" />
                                        <span className="sr-only">
                                            Download
                                        </span>
                                    </a>
                                </Button>
                            )}
                            {wp.url && (
                                <Button variant="ghost" size="sm" asChild>
                                    <a
                                        href={wp.url}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                    >
                                        <ExternalLink className="size-3.5" />
                                        <span className="sr-only">
                                            Open link
                                        </span>
                                    </a>
                                </Button>
                            )}
                        </div>
                    </div>
                ))}
            </div>
        </div>
    );
}
