// WxNotify dashboard service worker: shows push notifications sent by the server.
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

self.addEventListener('push', (event) => {
    let data = {};
    try {
        data = event.data ? event.data.json() : {};
    } catch (e) {
        data = { body: event.data ? event.data.text() : '' };
    }

    event.waitUntil(
        self.registration.showNotification(data.title || 'WxNotify', {
            body: data.body || '',
            icon: '/favicon.ico',
            tag: data.tag || undefined,
            data: { url: data.url || '/dashboard' },
        })
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    let url = (event.notification.data && event.notification.data.url) || '/dashboard';
    try {
        if (new URL(url, self.location.origin).origin !== self.location.origin) {
            url = '/dashboard';
        }
    } catch (e) {
        url = '/dashboard';
    }

    event.waitUntil(
        (async () => {
            const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });

            // Only a client this worker controls can be navigated: the scope is
            // /dashboard/, so a same-origin tab parked on / is uncontrolled and
            // WindowClient.navigate() rejects with a TypeError there. Picking
            // the first match regardless raised an unrelated tab, left the
            // rejection unhandled, and never went to the notification's URL.
            const target = windows.find((client) => {
                if (!('focus' in client) || typeof client.navigate !== 'function') {
                    return false;
                }
                try {
                    return new URL(client.url).pathname.startsWith('/dashboard');
                } catch (e) {
                    return false;
                }
            });

            if (target) {
                try {
                    await target.navigate(url);
                    return target.focus();
                } catch (e) {
                    // Navigation refused (the client stopped being controlled,
                    // or is cross-origin after all) — open a fresh window below.
                }
            }

            return self.clients.openWindow(url);
        })()
    );
});
