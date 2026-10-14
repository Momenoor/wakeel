<?php

namespace App\Filament\Support;

use App\Models\CalendarEvent;
use Carbon\Carbon;
use Closure;
use Filament\Forms\Components\Placeholder;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * The meetings already on a day, shown under the date of a new one — in the
 * calendar's form, the matter's event, a meeting made from a letter —
 * so two are not put on top of each other without seeing it.
 */
class SameDayMeetings
{
    /** How many are listed; the rest are counted. */
    public const LIMIT = 8;

    /**
     * The events on a day, earliest first.
     *
     * @return \Illuminate\Support\Collection<int, CalendarEvent>
     */
    public static function on(mixed $date, ?int $exceptId = null)
    {
        try {
            $day = Carbon::parse((string) $date, config('app.timezone'));
        } catch (Throwable) {
            return collect();
        }

        return CalendarEvent::query()
            ->with(['matter', 'matters'])
            ->whereBetween('start_datetime', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
            ->when($exceptId, fn ($query) => $query->whereKeyNot($exceptId))
            ->orderBy('start_datetime')
            ->limit(self::LIMIT + 1)
            ->get();
    }

    /**
     * The list as HTML (empty: nothing that day).
     */
    public static function html(mixed $date, ?int $exceptId = null): string
    {
        $events = self::on($date, $exceptId);

        if ($events->isEmpty()) {
            return '';
        }

        $rows = $events->take(self::LIMIT)->map(function (CalendarEvent $event): string {
            $matters = $event->matters->push($event->matter)->filter()->unique('id')->map(fn ($matter) => $matter->reference)->implode('، ');
            $time = $event->is_all_day ? __('All Day') : $event->start_datetime->format('H:i').($event->end_datetime ? '–'.$event->end_datetime->format('H:i') : '');

            return '<li style="margin-bottom: 2px;"><strong dir="ltr" style="unicode-bidi: isolate;">'.e($time).'</strong> '.e($event->title)
                .($matters !== '' ? ' <span style="opacity: .7;">('.e($matters).')</span>' : '')
                .($event->is_teams_meeting ? ' <span style="opacity: .7;">· Teams</span>' : '').'</li>';
        })->implode('');

        $more = $events->count() > self::LIMIT ? '<li style="opacity: .7;">…</li>' : '';

        return '<div style="border-radius: .5rem; padding: .5rem .75rem; background: rgba(245, 158, 11, .12); border: 1px solid rgba(245, 158, 11, .35); font-size: .875rem;">'
            .'<div style="font-weight: 600; margin-bottom: 4px;">'.e(__('Already on this day')).'</div>'
            .'<ul style="margin: 0; padding-inline-start: 1.1rem; list-style: disc;">'.$rows.$more.'</ul></div>';
    }

    /**
     * The field to put under a date: it follows the date live and is not
     * there when the day is free. The event being edited is left out.
     *
     * @param  Closure  $date  the chosen date (or date and time), e.g. fn (Get $get) => $get('start_datetime')
     */
    public static function placeholder(Closure $date, string $name = 'same_day_meetings'): Placeholder
    {
        return Placeholder::make($name)
            ->hiddenLabel()
            ->columnSpanFull()
            ->dehydrated(false)
            ->content(fn ($get, $record = null) => new HtmlString(self::html($date($get), $record instanceof CalendarEvent ? $record->getKey() : null)))
            ->visible(fn ($get, $record = null): bool => filled($date($get)) && self::html($date($get), $record instanceof CalendarEvent ? $record->getKey() : null) !== '');
    }
}
