{{--
    Desktop notifications: the system's own notifications, shown by the
    browser as well.

    - Web Push: once allowed, this browser subscribes (a service worker,
      public/push-sw.js) and notifications arrive even with no Wakeel tab
      open. Re-checked once per browser session, and re-subscribed if the
      server's keys changed.
    - With a tab open, the poller (NotificationPoller) also sends each new
      notification as a "wakeel-desktop-notification" browser event, shown
      when Wakeel is in the background — for browsers without push. One tag
      per notification, so push and tabs never show it twice.

    The bell asks for permission — browsers only allow that from a click —
    and disappears once answered. When blocked, a crossed bell says how to
    allow it again.
--}}
<div
    x-data="{
        state: ('Notification' in window) ? Notification.permission : 'unsupported',
        pushKey: @js($pushKey),
        enable() {
            Notification.requestPermission().then((permission) => {
                this.state = permission;
                if (permission === 'granted') {
                    new Notification(@js(__('Desktop notifications are on')), {
                        body: @js(__('You will be notified here even when Wakeel is in the background.')),
                        icon: @js($icon),
                        tag: 'wakeel-enabled',
                    });
                    this.subscribe(true);
                }
            });
        },
        keyBytes(base64) {
            const padded = (base64 + '='.repeat((4 - base64.length % 4) % 4)).replace(/-/g, '+').replace(/_/g, '/');
            return Uint8Array.from(atob(padded), (c) => c.charCodeAt(0));
        },
        sameKey(a, b) {
            if (! a) { return false; }
            const x = new Uint8Array(a);
            return x.length === b.length && x.every((v, i) => v === b[i]);
        },
        async subscribe(force = false) {
            if (this.state !== 'granted' || ! this.pushKey || ! ('serviceWorker' in navigator) || ! ('PushManager' in window)) { return; }
            try {
                const registration = await navigator.serviceWorker.register(@js($workerUrl));
                await navigator.serviceWorker.ready;
                const key = this.keyBytes(this.pushKey);
                let subscription = await registration.pushManager.getSubscription();
                if (subscription && ! this.sameKey(subscription.options.applicationServerKey, key)) {
                    await subscription.unsubscribe();
                    subscription = null;
                }
                if (! subscription) {
                    subscription = await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key });
                }
                const json = subscription.toJSON();
                const marker = 'wakeel-push:' + json.endpoint;
                try { if (! force && sessionStorage.getItem(marker)) { return; } } catch (e) {}
                const response = await fetch(@js($subscribeUrl), {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': @js(csrf_token()) },
                    body: JSON.stringify({
                        endpoint: json.endpoint,
                        keys: json.keys,
                        contentEncoding: (PushManager.supportedContentEncodings || ['aes128gcm'])[0],
                    }),
                });
                if (response.ok) { try { sessionStorage.setItem(marker, '1'); } catch (e) {} }
            } catch (e) {
                console.warn('Wakeel push notifications:', e);
            }
        },
    }"
    x-init="subscribe()"
    x-on:wakeel-desktop-notification.window="
        const n = $event.detail;
        if (state !== 'granted' || (document.visibilityState === 'visible' && document.hasFocus())) { return; }
        const shown = new Notification(n.title, { body: n.body || '', icon: @js($icon), tag: 'wakeel-' + n.id });
        shown.onclick = () => { window.focus(); if (n.url) { window.location.href = n.url; } shown.close(); };
    "
    class="flex items-center"
>
    <template x-if="state === 'default'">
        <x-filament::icon-button
            icon="heroicon-o-bell-alert"
            color="warning"
            :label="__('Enable desktop notifications')"
            :tooltip="__('Enable desktop notifications')"
            x-on:click="enable()"
        />
    </template>

    <template x-if="state === 'denied'">
        <x-filament::icon-button
            icon="heroicon-o-bell-slash"
            color="gray"
            :label="__('Desktop notifications are blocked')"
            :tooltip="__('Desktop notifications are blocked for this site. Allow them from the lock icon in the address bar, then reload.')"
        />
    </template>
</div>
