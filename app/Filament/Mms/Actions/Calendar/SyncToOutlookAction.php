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

        $this->label(__('Sync to Outlook'))
            ->icon('heroicon-o-arrow-path')
            ->visible(fn ($record) => app(OutlookCalendarService::class)->isConfigured()
                && (($record instanceof CalendarEvent && ! $record->synced_to_outlook) || ($record instanceof Matter && $record->calendarEvents()->where('synced_to_outlook', false)->exists())))
            ->action(function ($record, OutlookCalendarService $outlookService) {
                if ($record instanceof Matter) {
                    $record = $record->calendarEvents()->where('synced_to_outlook', false)->latest()->first();
                }

                if (! $record instanceof CalendarEvent) {
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
