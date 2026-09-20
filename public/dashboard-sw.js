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
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
            for (const client of windows) {
                if ('focus' in client) {
                    client.navigate(url);
                    return client.focus();
                }
            }
            return self.clients.openWindow(url);
        })
    );
});
