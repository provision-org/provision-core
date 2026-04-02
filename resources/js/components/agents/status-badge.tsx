import { Badge } from '@/components/ui/badge';

export default function StatusBadge({ status }: { status: string }) {
    const variant =
        status === 'active'
            ? 'default'
            : status === 'paused' ||
                status === 'pending' ||
                status === 'deploying'
              ? 'secondary'
              : 'destructive';

    return <Badge variant={variant}>{status}</Badge>;
}
