<?php

namespace App\Observers;

use App\Models\CalendarEvent;
use App\Services\MMS\Calendar\CalendarAlarms;
use App\Services\MMS\Calendar\EventMatterLinker;
use App\Services\MMS\OutlookCalendarService;
use Filament\Notifications\Notification;
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
     * Changed in Wakeel (its title, time, place or text): the change put on Outlook — the shared calendar, the
     * calendars of those invited and their phones — or the next sync would bring Outlook's back over it. Its
     * reminder items follow within the minute (CalendarAlarms). Kept here whatever Outlook answers; a refusal
     * is said.
     */
    public function updated(CalendarEvent $event): void
    {
        $changed = array_values(array_intersect(array_keys($event->getChanges()), CalendarEvent::OUTLOOK_FIELDS));

        if ($event->fromOutlook || blank($event->outlook_event_id) || $changed === []) {
            return;
        }

        $outlook = app(OutlookCalendarService::class);

        if (! $outlook->isConfigured()) {
            return;
        }

        try {
            $outlook->pushChanges($event, $changed);
        } catch (\Throwable $e) {
            Log::warning('Calendar event '.$event->id.' changed here, not in Outlook: '.$e->getMessage());
            Notification::make()
                ->warning()
                ->title(__('Saved, but Outlook was not updated'))
                ->body($e->getMessage())
                ->send();
        }
    }

    /**
     * Its reminder items taken out of the people's calendars before the event (and its alarms) go.
     */
    public function deleting(CalendarEvent $event): void
    {
        try {
            app(CalendarAlarms::class)->forget($event);
        } catch (\Throwable $e) {
            Log::warning('Failed to take the reminders of calendar event '.$event->id.' away: '.$e->getMessage());
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
