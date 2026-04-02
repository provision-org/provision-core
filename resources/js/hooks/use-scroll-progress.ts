import { useEffect, useState } from 'react';

export function useScrollProgress(
    ref: React.RefObject<HTMLElement | null>,
    offset = 0.2,
) {
    const [progress, setProgress] = useState(0);

    useEffect(() => {
        const el = ref.current;
        if (!el) return;

        const onScroll = () => {
            const rect = el.getBoundingClientRect();
            const vh = window.innerHeight;
            const start = vh * (1 - offset);
            const end = -rect.height * offset;
            const raw = 1 - (rect.top - end) / (start - end);
            setProgress(Math.max(0, Math.min(1, raw)));
        };

        window.addEventListener('scroll', onScroll, { passive: true });
        onScroll();
        return () => window.removeEventListener('scroll', onScroll);
    }, [ref, offset]);

    return progress;
}
