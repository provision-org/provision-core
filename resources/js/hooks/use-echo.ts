import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { useEffect } from 'react';

(window as unknown as Record<string, unknown>).Pusher = Pusher;

let echoInstance: Echo<'reverb'> | null = null;

function getEcho(): Echo<'reverb'> {
    if (!echoInstance) {
        echoInstance = new Echo({
            broadcaster: 'reverb',
            key: import.meta.env.VITE_REVERB_APP_KEY,
            wsHost: import.meta.env.VITE_REVERB_HOST,
            wsPort: import.meta.env.VITE_REVERB_PORT ?? 8080,
            wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
            forceTLS:
                (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
            enabledTransports: ['ws', 'wss'],
        });
    }

    return echoInstance;
}

export function useEcho<T = unknown>(
    channelName: string,
    event: string,
    callback: (data: T) => void,
) {
    useEffect(() => {
        const echo = getEcho();
        const channel = echo.private(channelName);

        channel.listen(event, callback);

        return () => {
            channel.stopListening(event);
            echo.leave(`private-${channelName}`);
        };
    }, [channelName, event]); // eslint-disable-line react-hooks/exhaustive-deps
}
