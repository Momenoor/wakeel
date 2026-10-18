{{--
    With Pusher, what the pages used to poll for comes to them (App\Support\Live):
      - "records changed" (RecordsChanged): told to the page as wakeel-records-changed, for what
        listens in the browser (the lists' tab counts);
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

        const changed = () => window.dispatchEvent(new CustomEvent('wakeel-records-changed'));

        const start = () => {
            window.Echo.private('records').listen('.records.changed', changed);

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
