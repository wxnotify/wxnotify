import Echo from 'laravel-echo';

import Pusher from 'pusher-js';
window.Pusher = Pusher;

/*
 * Reverb is optional at runtime: with no app key or a blocked socket the page falls back to
 * polling, so creating Echo must never throw. The page listens for `wx-echo-ready` (or reads
 * window.Echo / window.wxEchoUnavailable if it loaded first).
 */
try {
    const config = window.wxReverbConfig;
    const key = config ? config.key : import.meta.env.VITE_REVERB_APP_KEY;

    if (!key) {
        throw new Error('No Reverb app key');
    }

    const connection = config
        ? {
              wsHost: config.host || window.location.hostname,
              wsPort: config.port,
              wssPort: config.port,
              forceTLS: config.scheme === 'https',
          }
        : {
              wsHost: import.meta.env.VITE_REVERB_HOST,
              wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
              wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
              forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
          };

    window.Echo = new Echo({
        broadcaster: 'reverb',
        key,
        ...connection,
        enabledTransports: ['ws', 'wss'],
    });
} catch (error) {
    window.Echo = undefined;
    window.wxEchoUnavailable = true;
}

window.dispatchEvent(new CustomEvent('wx-echo-ready'));
