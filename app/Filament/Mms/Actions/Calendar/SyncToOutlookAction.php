<?php

namespace App\Filament\Mms\Actions\Calendar;

use App\Jobs\PushCalendarEventToOutlook;
use App\Models\CalendarEvent;
use App\Models\Matter;
use App\Services\MMS\OutlookCalendarService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

class SyncToOutlookAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'syncToOutlook';
    }

    protected function setUp(): void
    {
        parent::setUp();

        // An event: put on Outlook, or — already there — sent again as it is here (its title, time, place and
        // text), when a change did not reach it. A matter: its latest event not on Outlook yet. On the queue:
        // Microsoft took up to fourteen seconds here; whoever asked is told in the bell.
        $this->label(__('Sync to Outlook'))
            ->icon('heroicon-o-arrow-path')
            ->visible(fn ($record) => app(OutlookCalendarService::class)->isConfigured()
                && ($record instanceof CalendarEvent || ($record instanceof Matter && $record->calendarEvents()->where('synced_to_outlook', false)->exists())))
            ->action(function ($record) {
                if ($record instanceof Matter) {
                    $record = $record->calendarEvents()->where('synced_to_outlook', false)->latest()->first();
                }

                if (! $record instanceof CalendarEvent) {
                    return;
                }

                PushCalendarEventToOutlook::dispatch((int) $record->getKey(), null, auth()->id(), filled($record->outlook_event_id))->afterCommit();
                Notification::make()->info()->title(__('Sending to Outlook'))->body(__('You are told in the bell when it is done.'))->send();
            });
    }
}
