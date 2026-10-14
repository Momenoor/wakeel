<?php

namespace App\Filament\Support;

use App\Models\CalendarEvent;
use Carbon\Carbon;
use Carbon\CarbonInterface;
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
     * The meetings a new one, from its start to its end (an hour when it has
     * none), would run during — any that is on in that time. All-day events
     * are not meetings that take the time.
     *
     * @return \Illuminate\Support\Collection<int, CalendarEvent>
     */
    public static function overlapping(CarbonInterface $start, ?CarbonInterface $end = null, ?int $exceptId = null)
    {
        $start = Carbon::instance($start);
        $end = $end ? Carbon::instance($end) : $start->copy()->addHour();

        if ($end->lessThanOrEqualTo($start)) {
            $end = $start->copy()->addHour();
        }

        return CalendarEvent::query()
            ->with(['matter', 'matters'])
            ->where(fn ($query) => $query->whereNull('is_all_day')->orWhere('is_all_day', false))
            ->where('start_datetime', '<', $end->format('Y-m-d H:i:s'))
            // An event with no end takes its hour.
            ->whereRaw('COALESCE(end_datetime, '.self::plusHour().') > ?', [$start->format('Y-m-d H:i:s')])
            ->when($exceptId, fn ($query) => $query->whereKeyNot($exceptId))
            ->orderBy('start_datetime')
            ->limit(self::LIMIT)
            ->get();
    }

    /** SQL for "start_datetime plus an hour", for the database in use. */
    private static function plusHour(): string
    {
        return match (\Illuminate\Support\Facades\DB::connection()->getDriverName()) {
            'sqlite' => "datetime(start_datetime, '+1 hour')",
            'pgsql' => "start_datetime + interval '1 hour'",
            default => 'DATE_ADD(start_datetime, INTERVAL 1 HOUR)',
        };
    }

    /**
     * Those meetings as lines: "10:00–11:00 title (matter)".
     *
     * @param  \Illuminate\Support\Collection<int, CalendarEvent>  $events
     * @return list<string>
     */
    public static function lines($events): array
    {
        return $events->map(function (CalendarEvent $event): string {
            $matters = $event->matters->push($event->matter)->filter()->unique('id')->map(fn ($matter) => $matter->reference)->implode('، ');

            return $event->start_datetime->format('d/m/Y H:i').($event->end_datetime ? '–'.$event->end_datetime->format('H:i') : '').' — '.$event->title.($matters !== '' ? ' ('.$matters.')' : '');
        })->values()->all();
    }

    /**
     * The list as HTML (empty: nothing that day).
     */
    public static function html(mixed $date, ?int $exceptId = null): string
    {
        // Filament asks for it again for the field's visibility and its content, and on each render: once a request.
        $key = 'same-day-meetings:'.json_encode([$date instanceof \DateTimeInterface ? $date->format('Y-m-d') : substr((string) $date, 0, 10), $exceptId]);

        return request()->attributes->get($key) ?? tap(self::build($date, $exceptId), fn (string $html) => request()->attributes->set($key, $html));
    }

    private static function build(mixed $date, ?int $exceptId): string
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
