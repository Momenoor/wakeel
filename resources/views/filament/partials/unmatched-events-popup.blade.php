{{--
    For super admins and admins: calendar events naming a matter number that
    is not in the system, at most once an hour. The list is
    UnmatchedEventReferences; the full table is the dashboard widget.
--}}
<x-admin-popup
    id="wakeel-unmatched-events"
    :heading="trans_choice('{1} 1 event names a matter that is not in the system|[2,*] :count events name a matter that is not in the system', $count, ['count' => $count])"
    :description="__('Add the matter, or correct the event\'s title or linked matters.')"
>
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

    <x-slot name="actions">
        <x-filament::button tag="a" :href="$dashboardUrl" icon="heroicon-o-arrow-top-right-on-square">
            {{ __('Open dashboard') }}
        </x-filament::button>
    </x-slot>
</x-admin-popup>
