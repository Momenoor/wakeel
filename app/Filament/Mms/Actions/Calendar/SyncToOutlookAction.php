<?php

namespace App\Filament\Mms\Actions\Calendar;

use App\Models\CalendarEvent;
use App\Models\Matter;
use App\Services\MMS\OutlookCalendarService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

class SyncToOutlookAction extends Action
{
    use SyncsEventToOutlook;

    public static function getDefaultName(): ?string
    {
        return 'syncToOutlook';
    }

    protected function setUp(): void
    {
        parent::setUp();

        // An event: put on Outlook, or — already there — sent again as it is here (its title, time, place and
        // text), when a change did not reach it. A matter: its latest event not on Outlook yet.
        $this->label(__('Sync to Outlook'))
            ->icon('heroicon-o-arrow-path')
            ->visible(fn ($record) => app(OutlookCalendarService::class)->isConfigured()
                && ($record instanceof CalendarEvent || ($record instanceof Matter && $record->calendarEvents()->where('synced_to_outlook', false)->exists())))
            ->action(function ($record, OutlookCalendarService $outlookService) {
                if ($record instanceof Matter) {
                    $record = $record->calendarEvents()->where('synced_to_outlook', false)->latest()->first();
                }

                if (! $record instanceof CalendarEvent) {
                    return;
                }

                if (filled($record->outlook_event_id)) {
                    try {
                        $outlookService->pushChanges($record, CalendarEvent::OUTLOOK_FIELDS);
                        Notification::make()->title(__('Updated in Outlook'))->success()->send();
                    } catch (\Throwable $e) {
                        Notification::make()->title(__('Outlook Sync Failed'))->body($e->getMessage())->danger()->send();
                    }

                    return;
                }

                if ($this->pushToOutlook($outlookService, $record)) {
                    Notification::make()
                        ->title(__('Synced to Outlook Successfully'))
                        ->success()
                        ->send();
                }
            });
    }
}
