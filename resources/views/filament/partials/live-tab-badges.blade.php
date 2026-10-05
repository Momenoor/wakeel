{{--
    Keeps a list page's tab counts current, through Filament's own API for
    deferred tab badges (Tab::deferBadge()): the tabs load their counts with
    $wire.callSchemaComponentMethod(<key>, 'getDeferredTabBadges'),
    and this calls it again —

      - after anything done on the page (an action, a bulk action, a
        restore): the counts the records moved between;
      - every minute, and when the tab comes back into view: what
        others changed meanwhile.

    Only while the page is on screen; the request is the counts alone, not
    the table. Rendered on the pages that have such tabs (see the panel
    provider's render hook).
--}}
<script>
    (() => {
        if (window.wakeelLiveTabBadges) {
            return;
        }
        window.wakeelLiveTabBadges = true;

        const METHOD = 'getDeferredTabBadges';
        let busy = false;
        let timer = null;

        // The tabs' bar, holding the badges in its Alpine data.
        const bar = () => document.querySelector('[x-data*="' + METHOD + '"]');
        const owner = (el) => el?.closest('[wire\\:id]')?.getAttribute('wire:id');

        const refresh = async () => {
            const nav = bar();
            if (! nav || busy || document.hidden) {
                return;
            }

            const wire = window.Livewire.find(owner(nav));
            // The tabs' key in the page's schema, as Filament wrote it.
            const key = nav.getAttribute('x-data').match(/callSchemaComponentMethod\(\s*['"]([^'"]+)['"]/)?.[1];
            if (! wire || ! key) {
                return;
            }

            busy = true;
            try {
                const badges = await wire.callSchemaComponentMethod(key, METHOD);
                if (badges) {
                    window.Alpine.$data(nav).deferredBadges = badges;
                }
            } catch (e) {
                // The next round tries again.
            } finally {
                busy = false;
            }
        };

        const soon = () => {
            clearTimeout(timer);
            timer = setTimeout(refresh, 400);
        };

        const start = () => {
            // Something done on the page — not the counts' own request.
            window.Livewire.interceptMessage(({ message, onSuccess }) => onSuccess(() => {
                const calls = message.calls ?? [];
                if (calls.length && ! calls.every((call) => call.method === 'callSchemaComponentMethod') && message.component.id === owner(bar())) {
                    soon();
                }
            }));

            setInterval(refresh, 60000);
            document.addEventListener('visibilitychange', () => document.hidden || soon());
        };

        window.Livewire ? start() : document.addEventListener('livewire:init', start);
    })();
</script>
