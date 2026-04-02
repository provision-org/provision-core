import { Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { Button } from '@/components/ui/button';
import type { PaginatedData } from '@/types';

export function Pagination<T>({ data }: { data: PaginatedData<T> }) {
    if (data.last_page <= 1) return null;

    return (
        <div className="flex items-center justify-between border-t px-1 pt-4">
            <p className="text-xs text-muted-foreground">
                Showing {data.from} to {data.to} of {data.total}
            </p>
            <div className="flex items-center gap-1">
                {data.links.map((link, i) => {
                    if (i === 0) {
                        return (
                            <Button
                                key="prev"
                                variant="ghost"
                                size="icon"
                                className="h-8 w-8"
                                disabled={!link.url}
                                asChild={!!link.url}
                            >
                                {link.url ? (
                                    <Link href={link.url} preserveScroll>
                                        <ChevronLeft className="h-4 w-4" />
                                    </Link>
                                ) : (
                                    <span>
                                        <ChevronLeft className="h-4 w-4" />
                                    </span>
                                )}
                            </Button>
                        );
                    }
                    if (i === data.links.length - 1) {
                        return (
                            <Button
                                key="next"
                                variant="ghost"
                                size="icon"
                                className="h-8 w-8"
                                disabled={!link.url}
                                asChild={!!link.url}
                            >
                                {link.url ? (
                                    <Link href={link.url} preserveScroll>
                                        <ChevronRight className="h-4 w-4" />
                                    </Link>
                                ) : (
                                    <span>
                                        <ChevronRight className="h-4 w-4" />
                                    </span>
                                )}
                            </Button>
                        );
                    }
                    return (
                        <Button
                            key={link.label}
                            variant={link.active ? 'default' : 'ghost'}
                            size="icon"
                            className="h-8 w-8 text-xs"
                            disabled={!link.url}
                            asChild={!!link.url && !link.active}
                        >
                            {link.url && !link.active ? (
                                <Link href={link.url} preserveScroll>
                                    <span>
                                        {link.label
                                            .replace(/&laquo;/g, '\u00AB')
                                            .replace(/&raquo;/g, '\u00BB')
                                            .replace(/&hellip;/g, '\u2026')}
                                    </span>
                                </Link>
                            ) : (
                                <span
                                    dangerouslySetInnerHTML={{
                                        __html: link.label,
                                    }}
                                />
                            )}
                        </Button>
                    );
                })}
            </div>
        </div>
    );
}
