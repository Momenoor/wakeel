/*
 * Wakeel's service worker: shows Web Push notifications even when no Wakeel
 * tab is open, and opens the notification's link when it is clicked; and,
 * with no connection, shows the "No connection" page (resources/views/offline)
 * instead of the browser's error — what makes Wakeel installable as an app.
 * Registered on every page (filament.partials.app-install), and by
 * filament.partials.desktop-notifications for push.
 */

// Its own name per version of the page kept; an older one is removed.
const OFFLINE_CACHE = 'wakeel-offline-v2';
const offlineUrl = () => new URL('offline', self.registration.scope).href;

self.addEventListener('install', (event) => {
    event.waitUntil((async () => {
        try {
            const cache = await caches.open(OFFLINE_CACHE);
            await cache.add(new Request(offlineUrl(), { cache: 'reload' }));
        } catch (e) {
            // No connection while installing: kept on the next visit.
        }
        await self.skipWaiting();
    })());
});

self.addEventListener('activate', (event) => event.waitUntil((async () => {
    const names = await caches.keys();
    await Promise.all(names.filter((name) => name.startsWith('wakeel-offline-') && name !== OFFLINE_CACHE).map((name) => caches.delete(name)));
    await self.clients.claim();
})()));

// A page opened: from the server always — only when it cannot be reached, the "No connection" page.
self.addEventListener('fetch', (event) => {
    if (event.request.mode !== 'navigate' || event.request.method !== 'GET') {
        return;
    }

    event.respondWith((async () => {
        try {
            return await fetch(event.request);
        } catch (e) {
            const kept = await caches.match(offlineUrl());

            return kept || Response.error();
        }
    })());
});

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
            // A call ringing: kept on screen until it is touched, and the phone
            // buzzes like a call (where the phone allows it).
            requireInteraction: !! data.requireInteraction,
            vibrate: Array.isArray(data.vibrate) ? data.vibrate : undefined,
            silent: !! data.close,
            data: { url: data.url || self.registration.scope },
        });

        // A call that is over (answered, declined, cancelled): its ringing
        // notification replaced by this one and both taken away at once.
        if (data.close) {
            const shown = await self.registration.getNotifications({ tag });
            shown.forEach((notification) => notification.close());

            return;
        }

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
