/*
 * Wakeel's service worker: shows Web Push notifications even when no Wakeel
 * tab is open, and opens the notification's link when it is clicked.
 * Registered by resources/views/filament/partials/desktop-notifications.blade.php.
 */

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

/** A Wakeel tab the user is looking at right now. */
async function wakeelInFront() {
    const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });

    return windows.some((client) => client.url.startsWith(self.registration.scope) && client.visibilityState === 'visible' && client.focused);
}

self.addEventListener('push', (event) => {
    let data = {};

    try {
        data = event.data ? event.data.json() : {};
    } catch (e) {
        data = { body: event.data ? event.data.text() : '' };
    }

    event.waitUntil((async () => {
        const tag = data.tag || 'wakeel-' + Date.now();

        await self.registration.showNotification(data.title || 'Wakeel', {
            body: data.body || '',
            icon: data.icon || undefined,
            badge: data.icon || undefined,
            // One per notification (one per conversation for chat): shown
            // once however many tabs and devices it reaches.
            tag,
            renotify: !! data.renotify,
            data: { url: data.url || self.registration.scope },
        });

        // Wakeel is in front: its own toast / chat window already shows it.
        // A push must still show something, so it is closed straight away.
        if (await wakeelInFront()) {
            const shown = await self.registration.getNotifications({ tag });
            shown.forEach((notification) => notification.close());
        }
    })());
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
