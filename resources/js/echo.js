import Echo from 'laravel-echo';

import Pusher from 'pusher-js';
window.Pusher = Pusher;

/*
 * Reverb is optional at runtime: with no app key or a blocked socket the page falls back to
 * polling, so creating Echo must never throw. The page listens for `wx-echo-ready` (or reads
 * window.Echo / window.wxEchoUnavailable if it loaded first).
 */
try {
    const key = import.meta.env.VITE_REVERB_APP_KEY;

    if (!key) {
        throw new Error('No Reverb app key');
    }

    window.Echo = new Echo({
        broadcaster: 'reverb',
        key,
        wsHost: import.meta.env.VITE_REVERB_HOST,
        wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
        wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
    });
} catch (error) {
    window.Echo = undefined;
    window.wxEchoUnavailable = true;
}

window.dispatchEvent(new CustomEvent('wx-echo-ready'));
