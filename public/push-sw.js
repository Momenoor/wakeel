/*
 * Wakeel's service worker: shows Web Push notifications even when no Wakeel
 * tab is open, and opens the notification's link when it is clicked.
 * Registered by resources/views/filament/partials/desktop-notifications.blade.php.
 */

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
        self.registration.showNotification(data.title || 'Wakeel', {
            body: data.body || '',
            icon: data.icon || undefined,
            badge: data.icon || undefined,
            // One per notification: a tab that already showed it is
            // replaced, not doubled.
            tag: data.tag || undefined,
            data: { url: data.url || self.registration.scope },
        }),
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const url = (event.notification.data && event.notification.data.url) || self.registration.scope;

    event.waitUntil((async () => {
        const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });

        // An open Wakeel tab: bring it forward on the notification's page.
        for (const client of windows) {
            if (client.url.startsWith(self.registration.scope)) {
                await client.focus();

                try {
                    await client.navigate(url);
                } catch (e) {
                    // Not controlled by this worker yet — open the page instead.
                    await self.clients.openWindow(url);
                }

                return;
            }
        }

        await self.clients.openWindow(url);
    })());
});
