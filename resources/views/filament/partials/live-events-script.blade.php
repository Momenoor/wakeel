{{--
    With Pusher, what the pages used to poll for comes to them (App\Support\Live):
      - "records changed" (RecordsChanged): told to the page as wakeel-records-changed, for what
        listens in the browser (the lists' tab counts), and a resource's list on screen is read again
        when the table it lists is the one that changed (Live::listMarker()) — not while a window is
        open over it or the tab is in the background: then once that is over;
      - the connection back after being lost (a phone that slept, a dropped network): every
        component that keeps current over Pusher catches up once (Livewire event
        wakeel-reconnected), since what was sent meanwhile never reached it.
--}}
<script>
    (() => {
        if (window.wakeelLiveEvents) {
            return;
        }
        window.wakeelLiveEvents = true;

        // The lists waiting to be read again: a table's name, or '*' for whatever is on screen.
        const waiting = new Set();

        const refreshLists = () => {
            if (! waiting.size || document.hidden || document.querySelector('.fi-modal-open')) {
                return;
            }

            document.querySelectorAll('[data-wakeel-live-table]').forEach((marker) => {
                if (waiting.has('*') || waiting.has(marker.dataset.wakeelLiveTable)) {
                    window.Livewire?.find(marker.closest('[wire\:id]')?.getAttribute('wire:id'))?.$refresh();
                }
            });
            waiting.clear();
        };

        const changed = (table = '*') => {
            window.dispatchEvent(new CustomEvent('wakeel-records-changed', { detail: { table } }));
            waiting.add(table);
            refreshLists();
        };

        document.addEventListener('visibilitychange', refreshLists);
        // A window closed: what changed while it was open.
        window.addEventListener('modal-closed', () => setTimeout(refreshLists, 300));

        const start = () => {
            window.Echo.private('records').listen('.records.changed', (event) => changed(event?.table || '*'));

            const pusher = window.Echo.connector?.pusher;
            let lost = false;
            pusher?.connection.bind('state_change', (states) => {
                if (['unavailable', 'failed', 'disconnected'].includes(states.current)) {
                    lost = true;
                }
                if (states.current === 'connected' && lost) {
                    lost = false;
                    window.Livewire?.dispatch('wakeel-reconnected');
                    window.Livewire?.dispatch('databaseNotificationsSent');
                    changed();
                }
            });
        };

        window.Echo ? start() : window.addEventListener('EchoLoaded', start, { once: true });
    })();
</script>
