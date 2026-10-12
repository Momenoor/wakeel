{{--
    A Livewire request that failed — the "Error while loading the page"
    notification — told to the server (ClientErrorController): which
    component and action, the status (0: no answer at all), the page. Not
    those cut short by leaving the page, nor the report itself failing.
--}}
<script>
    (() => {
        let leaving = false;
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
            const started = performance.now();
            pusher.connection.bind('state_change', (states) => {
                if (['unavailable', 'failed'].includes(states.current)) {
                    report(0, 'echo: connection ' + states.current, started);
                }
            });
            pusher.bind('pusher:subscription_error', (error) => report(error?.status ?? 0, 'echo: channel refused', started));
        };
        if (window.Echo) { watchEcho(); } else { window.addEventListener('EchoLoaded', watchEcho, { once: true }); }

        document.addEventListener('livewire:init', () => {
            Livewire.interceptRequest(({ request, onError, onFailure }) => {
                const started = performance.now();
                onError(({ response }) => report(response?.status ?? 0, request, started));
                onFailure(() => report(0, request, started));
            });
        });
    })();
</script>
