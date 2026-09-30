<?php

namespace App\Services\MMS\Calendar;

use App\Models\CalendarEvent;
use App\Models\Matter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Calendar events whose titles name a matter number (639/2025 …) that no
 * matter in the system has — a matter not entered yet, or a typo in the
 * title. From 30 days ago onwards, so the list stays about what can still
 * be acted on.
 *
 * Worked out once and kept for ten minutes (it is shown on every page for
 * admins); forgotten whenever an event or a matter changes.
 */
class UnmatchedEventReferences
{
    public const CACHE_KEY = 'calendar.unmatched-event-references';

    public const DAYS_BACK = 30;

    /**
     * @return array<int, list<string>> event id => missing numbers ("639/2025")
     */
    public static function missing(): array
    {
        return Cache::remember(self::CACHE_KEY, now()->addMinutes(10), fn (): array => self::find());
    }

    public static function count(): int
    {
        return count(self::missing());
    }

    /**
     * @return Builder<CalendarEvent>
     */
    public static function events(): Builder
    {
        return CalendarEvent::query()->whereIn('id', array_keys(self::missing()))->orderBy('start_datetime');
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array<int, list<string>>
     */
    private static function find(): array
    {
        $refsByEvent = [];

        CalendarEvent::query()
            ->where('start_datetime', '>=', now()->subDays(self::DAYS_BACK)->startOfDay())
            ->select(['id', 'title'])
            ->chunkById(500, function ($events) use (&$refsByEvent) {
                foreach ($events as $event) {
                    $refs = MatterReferenceMatcher::references($event->title);

                    if ($refs !== []) {
                        $refsByEvent[$event->id] = $refs;
                    }
                }
            });

        if ($refsByEvent === []) {
            return [];
        }

        $existing = self::existing(collect($refsByEvent)->flatten(1)->unique(fn ($ref) => $ref['number'].'/'.$ref['year']));
        $links = self::linkCounts(array_keys($refsByEvent));
        $missing = [];

        foreach ($refsByEvent as $eventId => $refs) {
            // Linked by hand to as many matters as the title names numbers
            // (a mistyped number linked to the right matter): accounted for.
            if (($links[$eventId] ?? 0) >= count($refs)) {
                continue;
            }

            $gone = collect($refs)
                ->reject(fn ($ref) => isset($existing[$ref['number'].'/'.$ref['year']]))
                ->map(fn ($ref) => $ref['number'].'/'.$ref['year'])
                ->values()
                ->all();

            if ($gone !== []) {
                $missing[$eventId] = $gone;
            }
        }

        return $missing;
    }

    /**
     * How many matters each event is linked to.
     *
     * @param  list<int>  $eventIds
     * @return array<int, int>
     */
    private static function linkCounts(array $eventIds): array
    {
        $counts = [];

        foreach (array_chunk($eventIds, 500) as $chunk) {
            DB::table('calendar_event_matter')
                ->whereIn('calendar_event_id', $chunk)
                ->selectRaw('calendar_event_id, COUNT(DISTINCT matter_id) as links')
                ->groupBy('calendar_event_id')
                ->get()
                ->each(function (object $row) use (&$counts): void {
                    $counts[(int) $row->calendar_event_id] = (int) $row->links;
                });
        }

        return $counts;
    }

    /**
     * Which of these number/year pairs a matter has — a few queries, not
     * one per event.
     *
     * @param  Collection<int, array{number: string, year: int}>  $refs
     * @return array<string, true>
     */
    private static function existing(Collection $refs): array
    {
        $found = [];

        foreach ($refs->chunk(200) as $chunk) {
            Matter::query()
                ->where(function (Builder $query) use ($chunk) {
                    foreach ($chunk as $ref) {
                        $query->orWhere(fn (Builder $q) => $q->where('year', $ref['year'])->where('number', $ref['number']));
                    }
                })
                ->get(['number', 'year'])
                ->each(function (Matter $matter) use (&$found) {
                    $found[(ltrim((string) $matter->number, '0') ?: '0').'/'.$matter->year] = true;
                });
        }

        return $found;
    }
}
