<?php

namespace App\Observers;

use App\Models\CalendarEvent;
use App\Services\MMS\Calendar\EventMatterLinker;
use App\Services\MMS\OutlookCalendarService;
use Illuminate\Support\Facades\Log;

class CalendarEventObserver
{
    /**
     * A new or renamed event is linked straight away to the matters its
     * title names — links already made are kept (EventMatterLinker).
     */
    public function saved(CalendarEvent $event): void
    {
        if (! $event->wasRecentlyCreated && ! $event->wasChanged('title')) {
            return;
        }

        try {
            app(EventMatterLinker::class)->link($event);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Remove the event from Outlook when it is deleted locally.
     *
     * Guarded on two counts: an event that was never synced has a null
     * outlook_event_id and would hit a non-nullable string parameter, and a
     * Graph outage must not make local events undeletable — the local delete is
     * already committed by this point, so a remote failure is logged, not thrown.
     */
    public function deleted(CalendarEvent $event): void
    {
        if (blank($event->outlook_event_id)) {
            return;
        }

        try {
            app(OutlookCalendarService::class)->deleteEvent($event->outlook_event_id);
        } catch (\Throwable $e) {
            Log::warning('Failed to delete Outlook event for calendar event '.$event->id.': '.$e->getMessage());
        }
    }
}
