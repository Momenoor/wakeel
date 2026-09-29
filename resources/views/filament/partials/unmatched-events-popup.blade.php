{{--
    For super admins and admins: calendar events naming a matter number that
    is not in the system. Opens by itself at most once an hour in each
    browser — on any page, and also while a page stays open. The list is
    UnmatchedEventReferences; the full table is the dashboard widget.
--}}
<div
    x-data="{
        key: 'wakeel-unmatched-events-shown-at',
        hour: 60 * 60 * 1000,
        due() {
            try { return Date.now() - Number(localStorage.getItem(this.key) || 0) >= this.hour; } catch (e) { return true; }
        },
        show() {
            if (! this.due()) { return; }
            try { localStorage.setItem(this.key, String(Date.now())); } catch (e) {}
            this.$dispatch('open-modal', { id: 'wakeel-unmatched-events' });
        },
    }"
    x-init="setTimeout(() => show(), 1500); setInterval(() => show(), 60 * 1000)"
>
    <x-filament::modal
        id="wakeel-unmatched-events"
        icon="heroicon-o-exclamation-triangle"
        icon-color="warning"
        width="xl"
        {{-- Closes only with its own button, not a click outside or Esc. --}}
        :close-by-clicking-away="false"
        :close-by-escaping="false"
        :close-button="false"
        :heading="trans_choice('{1} 1 event names a matter that is not in the system|[2,*] :count events name a matter that is not in the system', $count, ['count' => $count])"
        :description="__('Add the matter, or correct the event\'s title or linked matters.')"
    >
        <div style="max-height: 50vh; overflow-y: auto;">
            <table style="width: 100%; border-collapse: collapse; font-size: .875rem;">
                <tbody>
                    @foreach ($rows as $row)
                        <tr style="border-bottom: 1px solid rgba(128,128,128,.15);">
                            <td style="padding: 6px 8px; white-space: nowrap; vertical-align: top;">{{ $row['date'] }}</td>
                            <td style="padding: 6px 8px; vertical-align: top;">{{ $row['title'] }}</td>
                            <td style="padding: 6px 8px; white-space: nowrap; vertical-align: top; color: rgb(220 38 38); font-weight: 600;" dir="ltr">{{ implode(', ', $row['missing']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            @if ($count > count($rows))
                <p style="opacity: .7; font-size: .75rem; margin-top: 8px;">{{ __('And :more more — see the dashboard.', ['more' => $count - count($rows)]) }}</p>
            @endif
        </div>

        <x-slot name="footerActions">
            <x-filament::button tag="a" :href="$dashboardUrl" icon="heroicon-o-arrow-top-right-on-square">
                {{ __('Open dashboard') }}
            </x-filament::button>
            <x-filament::button color="gray" x-on:click="$dispatch('close-modal', { id: 'wakeel-unmatched-events' })">
                {{ __('Close') }}
            </x-filament::button>
        </x-slot>
    </x-filament::modal>
</div>
