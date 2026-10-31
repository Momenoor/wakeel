{{--
    A Livewire request that failed — the "Error while loading the page"
    notification — told to the server (ClientErrorController): which
    component and action, the status (0: no answer at all), the page. Not
    those cut short by leaving the page, nor the report itself failing.
--}}
<script>
    (() => {
        // Not inside a frame (a preview): its page is not the one used.
        if (window.self !== window.top) {
            return;
        }

        let leaving = false;
        // A few a minute at most: one failure can set off others (each
        // failure's own notice is a request too) — a storm of reports
        // helped nobody, and the server turned them away (429).
        const LIMIT = 5;
        let sent = [];
        window.addEventListener('pagehide', () => { leaving = true; });
        window.addEventListener('beforeunload', () => { leaving = true; });

        const describe = (request) => {
            try {
                return (request?.payload?.components ?? []).map((c) => {
                    const memo = JSON.parse(c.snapshot).memo ?? {};
                    const calls = (c.calls ?? []).map((call) => call.method);
                    return (memo.name ?? 'livewire') + '@' + (calls.length ? [...new Set(calls)].join(',') : 'update');
                }).join(' + ') || 'livewire';
            } catch (e) {
                return 'livewire';
            }
        };

        const report = (status, request, started) => {
            if (leaving) {
                return;
            }
            const now = Date.now();
            sent = sent.filter((at) => now - at < 60000);
            if (sent.length >= LIMIT) {
                return;
            }
            sent.push(now);
            const name = typeof request === 'string' ? request : describe(request);
            try {
                fetch(@js(route('client-errors.store')), {
                    method: 'POST',
                    keepalive: true,
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                    },
                    body: JSON.stringify({ status: status || 0, name, page: location.href, ms: Math.round(performance.now() - started) }),
                }).catch(() => {});
            } catch (e) {}
        };

        // Pusher (live chat and notifications) failing — the connection lost
        // for good, or a channel refused — told too: the page then waits for
        // its slower checks.
        const watchEcho = () => {
            const pusher = window.Echo?.connector?.pusher;
            if (! pusher) {
                return;
            }
            pusher.connection.bind('state_change', (states) => {
                if (['unavailable', 'failed'].includes(states.current)) {
                    // Not a request: no duration (the time since the page opened said nothing, and topped the report).
                    report(0, 'echo: connection ' + states.current, performance.now());
                }
            });
            pusher.bind('pusher:subscription_error', (error) => report(error?.status ?? 0, 'echo: channel refused', performance.now()));
        };
        if (window.Echo) { watchEcho(); } else { window.addEventListener('EchoLoaded', watchEcho, { once: true }); }

        // A check the page makes on its own (new messages, notifications) that got no answer at all —
        // a phone that locked or put the page in the background mid-request, a dropped connection —
        // is not a notice for the user: the next check, seconds later, does it again. Filament's
        // notice reads its settings as the failure is handled; this hook is registered before
        // Filament's (it is in the head), so it can say "none" for that one failure. Errors the
        // server answered (a 500, an expired session) and failed actions the user clicked still show.
        const BACKGROUND = ['checkForNewMessages', 'checkNotifications', '$refresh'];
        const isBackground = (request) => {
            try {
                const components = request?.payload?.components ?? [];

                return components.length > 0 && components.every((c) => (c.calls ?? []).length > 0 && c.calls.every((call) => BACKGROUND.includes(call.method)));
            } catch (e) {
                return false;
            }
        };
        let quiet = false;
        let notices = window.filamentErrorNotifications;
        Object.defineProperty(window, 'filamentErrorNotifications', {
            configurable: true,
            get: () => (quiet ? null : notices),
            set: (value) => { notices = value; },
        });

        document.addEventListener('livewire:init', () => {
            Livewire.interceptRequest(({ request, onFailure }) => {
                if (! isBackground(request)) {
                    return;
                }
                onFailure(() => {
                    quiet = true;
                    setTimeout(() => { quiet = false; }, 0);
                });
            });

            Livewire.interceptRequest(({ request, onError, onFailure }) => {
                // Filament's own notices failing: as Filament itself, not
                // reported — they only follow another failure, already told.
                try {
                    const components = request?.payload?.components ?? [];
                    if (components.length > 0 && components.every((c) => JSON.parse(c.snapshot).data?.isFilamentNotificationsComponent)) {
                        return;
                    }
                } catch (e) {}
                const started = performance.now();
                onError(({ response }) => report(response?.status ?? 0, request, started));
                onFailure(() => report(0, request, started));
            });
        });
    })();
</script>
