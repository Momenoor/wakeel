<?php

namespace App\Services\MMS\Calendar;

use App\Models\CalendarEvent;
use App\Models\Matter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Links calendar events to the matters their titles name.
 *
 * Only ever adds: a link made by hand, or one the title no longer names,
 * is never removed here. An event linked to exactly one matter also gets
 * it as its main matter (matter_id); with several it is a "bulk" event.
 */
class EventMatterLinker
{
    /**
     * @return int how many links were added
     */
    /**
     * @param  array<string, int>|null  $matters  the matters already found for the references ("639/2025" => id)
     * @param  list<int>|null  $linked  the matters it is linked to already, read with others' (linkedTo())
     */
    public function link(CalendarEvent $event, ?array $matters = null, ?array $linked = null): int
    {
        // Not the numbers marked as not that matter for this event.
        $refs = $event->matterReferences();
        $matters ??= MatterReferenceMatcher::matterIdsFor($refs);
        $ids = [];

        foreach ($refs as $ref) {
            if (($id = $matters[MatterReferenceMatcher::key($ref)] ?? null) !== null) {
                $ids[] = $id;
            }
        }

        if ($event->matter_id !== null) {
            $ids[] = (int) $event->matter_id;
        }

        $ids = array_values(array_unique($ids));

        // Linked already to each: nothing to read or write.
        if ($ids === [] || ($linked !== null && array_diff($ids, $linked) === [])) {
            return 0;
        }

        $added = count($event->matters()->syncWithoutDetaching($ids)['attached']);

        // Nothing new: nothing to put in line.
        if ($added > 0) {
            $this->tidy($event);
        }

        return $added;
    }

    /**
     * Links every event the query finds; for events already in the system.
     *
     * @param  Builder<CalendarEvent>  $query
     * @return array{events: int, links: int}
     */
    public function linkAll(Builder $query): array
    {
        $events = 0;
        $links = 0;

        $query->select(['id', 'title', 'ignored_references', 'matter_id', 'type'])->chunkById(200, function ($chunk) use (&$events, &$links) {
            // The matters the whole chunk names, and those each is linked to, read at once.
            $matters = MatterReferenceMatcher::matterIdsFor($chunk->flatMap(fn (CalendarEvent $event): array => $event->matterReferences())->all());
            $linked = self::linkedTo($chunk->modelKeys());

            foreach ($chunk as $event) {
                $added = $this->link($event, $matters, $linked[$event->getKey()] ?? []);
                $links += $added;
                $events += $added > 0 ? 1 : 0;
            }
        });

        return ['events' => $events, 'links' => $links];
    }

    /**
     * The matters each event is linked to, in one read.
     *
     * @param  list<int>  $eventIds
     * @return array<int, list<int>>
     */
    public static function linkedTo(array $eventIds): array
    {
        return collect($eventIds)->chunk(500)
            ->flatMap(fn ($ids) => DB::table('calendar_event_matter')->whereIn('calendar_event_id', $ids->all())->get(['calendar_event_id', 'matter_id']))
            ->groupBy('calendar_event_id')
            ->map(fn ($rows): array => $rows->pluck('matter_id')->map(fn ($id): int => (int) $id)->all())
            ->all();
    }

    /**
     * The matters' next session as an event's matters change (a bulk session's, or one that sets it): a matter
     * added takes the event's date, as those chosen when it was made; one removed whose next session was this
     * event's takes its next event ahead, or none.
     *
     * @param  list<int>  $added
     * @param  list<int>  $removed
     */
    public function followSessionDate(CalendarEvent $event, array $added, array $removed): void
    {
        if ($added !== [] && $event->start_datetime) {
            Matter::query()->whereIn('id', $added)->update(['next_session_date' => $event->start_datetime]);
        }

        if ($removed === [] || ! $event->start_datetime) {
            return;
        }

        Matter::query()->whereIn('id', $removed)->where('next_session_date', $event->start_datetime)->get()
            ->each(fn (Matter $matter) => $matter->update([
                'next_session_date' => $matter->linkedCalendarEvents()->whereKeyNot($event->getKey())->where('start_datetime', '>', now())->min('start_datetime'),
            ]));
    }

    /**
     * Main matter and single/bulk kept in line with the linked matters —
     * after linking here or by hand.
     */
    public function tidy(CalendarEvent $event): void
    {
        $linked = $event->matters()->pluck('matters.id');
        $changes = ['type' => $linked->count() > 1 ? 'bulk' : 'single'];

        if ($event->matter_id === null && $linked->count() === 1) {
            $changes['matter_id'] = $linked->first();
        }

        if ($event->matter_id !== null && $linked->isNotEmpty() && ! $linked->contains($event->matter_id)) {
            $changes['matter_id'] = $linked->count() === 1 ? $linked->first() : null;
        }

        if ($linked->isEmpty()) {
            $changes['matter_id'] = null;
        }

        $event->forceFill($changes);

        if ($event->isDirty()) {
            $event->saveQuietly();
        }
    }
}
