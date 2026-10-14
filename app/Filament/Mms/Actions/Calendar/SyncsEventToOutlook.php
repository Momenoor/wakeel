<?php

namespace App\Filament\Mms\Actions\Calendar;

use App\Models\CalendarEvent;
use App\Services\MMS\OutlookCalendarService;
use Filament\Notifications\Notification;

/**
 * Putting a calendar event on Outlook from an action: done, or the reason it
 * was not shown to the user — the same for every action that does it.
 */
trait SyncsEventToOutlook
{
    /**
     * @return bool whether the event reached Outlook
     */
    protected function pushToOutlook(OutlookCalendarService $outlook, CalendarEvent $event): bool
    {
        try {
            $outlook->pushEvent($event);

            return true;
        } catch (\Exception $e) {
            Notification::make()
                ->title(__('Outlook Sync Failed'))
                ->body($e->getMessage())
                ->danger()
                ->send();

            return false;
        }
    }
}
