import { useEffect, useRef } from 'react';

type TeamAvatarProps = {
    name: string;
    size?: number;
    className?: string;
};

function hashString(str: string): number {
    let hash = 0;
    for (let i = 0; i < str.length; i++) {
        hash = str.charCodeAt(i) + ((hash << 5) - hash);
        hash |= 0;
    }
    return Math.abs(hash);
}

export function TeamAvatar({
    name,
    size = 36,
    className = '',
}: TeamAvatarProps) {
    const canvasRef = useRef<HTMLCanvasElement>(null);

    useEffect(() => {
        const canvas = canvasRef.current;
        if (!canvas) return;
        const ctx = canvas.getContext('2d');
        if (!ctx) return;

        const dpr = window.devicePixelRatio || 1;
        canvas.width = size * dpr;
        canvas.height = size * dpr;
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);

        const hash = hashString(name);
        const hue = hash % 360;
        const hue2 = (hue + 40) % 360;

        const isDark = document.documentElement.classList.contains('dark');
        const sat = isDark ? '55%' : '50%';
        const light1 = isDark ? '45%' : '52%';
        const light2 = isDark ? '35%' : '42%';

        // Gradient background
        const grad = ctx.createLinearGradient(0, 0, size, size);
        grad.addColorStop(0, `hsl(${hue}, ${sat}, ${light1})`);
        grad.addColorStop(1, `hsl(${hue2}, ${sat}, ${light2})`);

        const r = size * 0.22;
        ctx.beginPath();
        ctx.roundRect(0, 0, size, size, r);
        ctx.fillStyle = grad;
        ctx.fill();

        // Dot grid pattern
        const dotSpacing = size / 6;
        const dotR = size * 0.022;
        const seed = hash;

        for (let row = 0; row < 7; row++) {
            for (let col = 0; col < 7; col++) {
                const x = col * dotSpacing + dotSpacing * 0.3;
                const y = row * dotSpacing + dotSpacing * 0.3;

                // Use hash to vary dot visibility
                const idx = row * 7 + col;
                const show =
                    ((seed >> (idx % 16)) ^ (seed >> ((idx + 7) % 16))) & 1;
                if (!show && idx % 3 !== 0) continue;

                // Vary opacity based on position (brighter toward top-left)
                const distFromCenter = Math.sqrt(
                    (x / size - 0.4) ** 2 + (y / size - 0.4) ** 2,
                );
                const alpha = Math.max(0.15, 0.55 - distFromCenter * 0.6);

                ctx.beginPath();
                ctx.arc(x, y, dotR + (idx % 2) * dotR * 0.5, 0, Math.PI * 2);
                ctx.fillStyle = `rgba(255, 255, 255, ${alpha})`;
                ctx.fill();
            }
        }

        // Letter
        const letter = name.charAt(0).toUpperCase();
        const fontSize = size * 0.42;
        ctx.font = `600 ${fontSize}px 'Plus Jakarta Sans', system-ui, sans-serif`;
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillStyle = 'rgba(255, 255, 255, 0.95)';
        ctx.fillText(letter, size / 2, size / 2 + fontSize * 0.04);
    }, [name, size]);

    return (
        <canvas
            ref={canvasRef}
            width={size}
            height={size}
            className={className}
            style={{ width: size, height: size, borderRadius: size * 0.22 }}
        />
    );
}
