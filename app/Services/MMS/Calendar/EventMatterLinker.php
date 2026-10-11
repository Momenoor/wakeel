<?php

namespace App\Services\MMS\Calendar;

use App\Models\CalendarEvent;
use Illuminate\Database\Eloquent\Builder;

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
     */
    public function link(CalendarEvent $event, ?array $matters = null): int
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

        if ($ids === []) {
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
            // The matters the whole chunk names, read at once.
            $matters = MatterReferenceMatcher::matterIdsFor($chunk->flatMap(fn (CalendarEvent $event): array => $event->matterReferences())->all());

            foreach ($chunk as $event) {
                $added = $this->link($event, $matters);
                $links += $added;
                $events += $added > 0 ? 1 : 0;
            }
        });

        return ['events' => $events, 'links' => $links];
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
