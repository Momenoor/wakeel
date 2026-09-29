<?php

namespace App\Services\MMS\Calendar;

use App\Models\CalendarEvent;
use App\Services\MMS\OutlookCalendarService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Keeps Wakeel's calendar in step with the shared Outlook calendar for a
 * window of dates:
 *
 * - an Outlook event not in Wakeel yet is added;
 * - one already here (by its Outlook id) takes Outlook's title, times,
 *   place and meeting link when they changed;
 * - one imported from Outlook that is gone from it, or cancelled there, is
 *   removed here — quietly, without the observer deleting it in Outlook;
 * - every event seen is linked to the matters its title names.
 *
 * Events created in Wakeel and pushed to Outlook are never removed here.
 */
class OutlookCalendarSync
{
    public function __construct(
        private readonly OutlookCalendarService $outlook,
        private readonly EventMatterLinker $linker,
    ) {}

    /**
     * @return array{added: int, updated: int, removed: int, linked: int}
     */
    public function sync(Carbon $from, Carbon $to): array
    {
        $counts = ['added' => 0, 'updated' => 0, 'removed' => 0, 'linked' => 0];
        $seen = [];
        // Links come from here and from CalendarEventObserver (new or
        // renamed events) — counted as what the run added in total.
        $linksBefore = DB::table('calendar_event_matter')->count();

        foreach ($this->outlook->eventsBetween($from, $to) as $remote) {
            $id = (string) ($remote['id'] ?? '');

            if ($id === '') {
                continue;
            }

            $local = CalendarEvent::withTrashed()->where('outlook_event_id', $id)->first();

            if ($remote['isCancelled'] ?? false) {
                continue;
            }

            $seen[] = $id;

            // Deleted in Wakeel on purpose (which also deletes it in Outlook,
            // so this is only a moment's lag): not brought back.
            if ($local?->trashed()) {
                continue;
            }

            $values = $this->valuesFrom($remote);

            if ($local === null) {
                $local = CalendarEvent::create([
                    ...$values,
                    'outlook_event_id' => $id,
                    'imported_from_outlook' => true,
                    'synced_to_outlook' => true,
                    'update_next_session_date' => false,
                    'type' => 'single',
                ]);
                $counts['added']++;
            } else {
                // An event written in Wakeel keeps its own description —
                // Outlook hands it back as HTML.
                if (! $local->imported_from_outlook) {
                    unset($values['description']);
                }

                $local->fill($values);

                if ($local->isDirty()) {
                    $local->save();
                    $counts['updated']++;
                }
            }

            $this->linker->link($local);
        }

        // Imported events in the window that Outlook no longer has.
        CalendarEvent::query()
            ->where('imported_from_outlook', true)
            ->whereNotNull('outlook_event_id')
            ->whereBetween('start_datetime', [$from, $to])
            ->whereNotIn('outlook_event_id', $seen)
            ->each(function (CalendarEvent $event) use (&$counts) {
                $event->deleteQuietly();
                $counts['removed']++;
            });

        $counts['linked'] = max(0, DB::table('calendar_event_matter')->count() - $linksBefore);

        return $counts;
    }

    /**
     * @param  array<string, mixed>  $remote
     * @return array<string, mixed>
     */
    private function valuesFrom(array $remote): array
    {
        return [
            'title' => mb_substr(trim((string) ($remote['subject'] ?? '')) ?: __('(no title)'), 0, 255),
            // Graph's dateTime is wall-clock time in its timeZone (UTC by
            // default) — see OutlookCalendarService::graphDateTimeToApp().
            'start_datetime' => $this->outlook->graphDateTimeToApp($remote['start']),
            'end_datetime' => isset($remote['end']) ? $this->outlook->graphDateTimeToApp($remote['end']) : null,
            'location' => mb_substr((string) ($remote['location']['displayName'] ?? ''), 0, 255),
            'description' => $remote['body']['content'] ?? null,
            'is_teams_meeting' => (bool) ($remote['isOnlineMeeting'] ?? false),
            'online_meeting_url' => $remote['onlineMeeting']['joinUrl'] ?? null,
            'is_all_day' => (bool) ($remote['isAllDay'] ?? false),
        ];
    }
}
