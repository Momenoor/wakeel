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

    While notifications are off (not asked yet, or blocked) one bell opens a
    window: "Enable" asks the browser — only allowed from a click — or, when
    blocked, the steps to allow it again. The window also opens by itself
    after every login until this browser has them on. The bell disappears
    once they are.
--}}
<div
    x-data="{
        state: ('Notification' in window) ? Notification.permission : 'unsupported',
        pushKey: @js($pushKey),
        promptAfterLogin: @js($promptAfterLogin),
        openPrompt() { this.$dispatch('open-modal', { id: 'wakeel-desktop-notifications' }); },
        closePrompt() { this.$dispatch('close-modal', { id: 'wakeel-desktop-notifications' }); },
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
    x-init="
        subscribe();
        // Every login, until this browser has notifications on.
        if (promptAfterLogin && (state === 'default' || state === 'denied')) { setTimeout(() => openPrompt(), 600); }
    "
    x-on:wakeel-desktop-notification.window="
        const n = $event.detail;
        if (state !== 'granted' || (document.visibilityState === 'visible' && document.hasFocus())) { return; }
        const shown = new Notification(n.title, { body: n.body || '', icon: @js($icon), tag: 'wakeel-' + n.id, renotify: String(n.id).startsWith('chat-') });
        shown.onclick = () => { window.focus(); if (n.url) { window.location.href = n.url; } shown.close(); };
    "
    class="flex items-center"
>
    {{-- One bell while notifications are off — not asked yet or blocked —
         opening the window below. --}}
    <template x-if="state === 'default' || state === 'denied'">
        <x-filament::icon-button
            icon="heroicon-o-bell-alert"
            color="warning"
            :label="__('Enable desktop notifications')"
            :tooltip="__('Enable desktop notifications')"
            x-on:click="openPrompt()"
        />
    </template>

    <x-filament::modal
        id="wakeel-desktop-notifications"
        icon="heroicon-o-bell-alert"
        icon-color="warning"
        alignment="center"
        width="md"
        :heading="__('Turn on desktop notifications')"
    >
        <div class="space-y-3 text-sm text-gray-600 dark:text-gray-300">
            <p>{{ __('Get new matters, requests, leave decisions and chat messages as notifications on this computer or phone — even when Wakeel is not open.') }}</p>

            <template x-if="state === 'denied'">
                <div class="space-y-2">
                    <p class="font-medium text-gray-950 dark:text-white">{{ __('Notifications are blocked for Wakeel in this browser. To allow them:') }}</p>
                    <ol class="space-y-1" style="list-style: decimal; padding-inline-start: 1.25rem;">
                        <li>{{ __('Click the lock (or settings) icon at the left of the address bar.') }}</li>
                        <li>{{ __('Set "Notifications" to "Allow".') }}</li>
                        <li>{{ __('Reload the page.') }}</li>
                    </ol>
                </div>
            </template>
        </div>

        <x-slot name="footerActions">
            <template x-if="state === 'default'">
                <x-filament::button color="warning" icon="heroicon-o-bell-alert" x-on:click="enable(); closePrompt()">
                    {{ __('Enable') }}
                </x-filament::button>
            </template>
            <template x-if="state === 'denied'">
                <x-filament::button x-on:click="window.location.reload()">
                    {{ __('I allowed it — reload') }}
                </x-filament::button>
            </template>
            <x-filament::button color="gray" x-on:click="closePrompt()">
                {{ __('Later') }}
            </x-filament::button>
        </x-slot>
    </x-filament::modal>
</div>
