{{-- A matter's sessions and events: upcoming first (soonest at the top),
     then past (latest at the top). Inline styles so it needs nothing from
     the panel theme's build. --}}
@php
    $cell = 'padding: 7px 8px; vertical-align: top;';
    $muted = 'opacity: .65; font-size: .75rem;';
@endphp

@foreach (['upcoming' => __('Upcoming'), 'past' => __('Past')] as $group => $heading)
    @continue($group === 'past' && $past->isEmpty())
    <div style="margin-bottom: 14px;">
        <div style="font-weight: 600; margin: 4px 0 6px;">
            {{ $heading }}
            <span style="{{ $muted }}">({{ $group === 'upcoming' ? $upcoming->count() : $past->count() }})</span>
        </div>

        @php($events = $group === 'upcoming' ? $upcoming : $past)

        @if ($events->isEmpty())
            <div style="{{ $muted }} padding: 4px 8px;">{{ __('No upcoming sessions or events.') }}</div>
        @else
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse; font-size: .875rem;">
                    <tbody>
                        @foreach ($events as $event)
                            <tr style="border-bottom: 1px solid rgba(128,128,128,.15); {{ $group === 'past' ? 'opacity: .8;' : '' }}">
                                <td style="{{ $cell }} white-space: nowrap; width: 1%;">
                                    <div style="font-weight: 600;">{{ $event->start_datetime?->translatedFormat('D d/m/Y') }}</div>
                                    <div style="{{ $muted }}">
                                        {{ $event->is_all_day ? __('All day') : $event->start_datetime?->translatedFormat('g:i A') }}
                                    </div>
                                </td>
                                <td style="{{ $cell }}">
                                    <div>{{ $event->title }}</div>
                                    <div style="{{ $muted }}">
                                        @if (filled($event->location)) {{ $event->location }} · @endif
                                        {{ $event->imported_from_outlook ? __('From Outlook') : __('Created in Wakeel') }}
                                        @if (($others = $event->matters->count() - 1) > 0)
                                            · {{ trans_choice('{1} with 1 other matter|[2,*] with :count other matters', $others, ['count' => $others]) }}
                                        @endif
                                    </div>
                                </td>
                                <td style="{{ $cell }} white-space: nowrap; text-align: end; width: 1%;">
                                    @if ($group === 'upcoming' && filled($event->online_meeting_url))
                                        <a href="{{ $event->online_meeting_url }}" target="_blank" rel="noopener"
                                           style="display: inline-block; padding: 3px 10px; border-radius: 6px; background: rgb(79 70 229); color: #fff; font-size: .75rem; font-weight: 600;">
                                            {{ __('Join') }}
                                        </a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endforeach

@if ($pastTotal > $past->count())
    <div style="{{ $muted }}">{{ __('Showing the latest :shown of :total past events.', ['shown' => $past->count(), 'total' => $pastTotal]) }}</div>
@endif
