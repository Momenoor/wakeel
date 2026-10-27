@php
    $align = \App\Support\MailStyle::start();
    $cell = 'padding: 8px; border-bottom: 1px solid #eeeeee; vertical-align: top; font-size: 13px; text-align: '.$align.';';
@endphp
<x-mail.layout :title="__('Events with a matter number not in the system')" :subtitle="__('Events with a matter number not in the system')" :width="640">
    <x-mail.p>{{ __('These calendar events name a matter number that no matter in Wakeel has. Add the matter, or correct the event\'s title or linked matters.') }}</x-mail.p>

    <table role="presentation" dir="{{ \App\Support\MailStyle::dir() }}" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse: collapse; margin: 8px 0 18px;">
        <tr>
            <th bgcolor="#f0f5fb" style="{{ $cell }} background-color: #f0f5fb; font-weight: bold;">{{ __('Date') }}</th>
            <th bgcolor="#f0f5fb" style="{{ $cell }} background-color: #f0f5fb; font-weight: bold;">{{ __('Event') }}</th>
            <th bgcolor="#f0f5fb" style="{{ $cell }} background-color: #f0f5fb; font-weight: bold;">{{ __('Matter number not found') }}</th>
        </tr>
        @foreach ($rows as $row)
            <tr>
                <td style="{{ $cell }} white-space: nowrap;">{{ $row['date'] }}</td>
                <td style="{{ $cell }}">{{ $row['title'] }}</td>
                <td dir="ltr" style="{{ $cell }} color: #c0392b; font-weight: bold; white-space: nowrap;">{{ implode(', ', $row['missing']) }}</td>
            </tr>
        @endforeach
    </table>

    <x-mail.actions>
        <x-mail.button :href="$dashboardUrl">{{ __('Open dashboard') }}</x-mail.button>
    </x-mail.actions>
</x-mail.layout>
