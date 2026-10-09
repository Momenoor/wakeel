<?php

namespace App\Filament\Mms\Actions\Calendar;

use App\Filament\Mms\Resources\CalendarEvents\Schemas\CalendarEventForm;
use App\Models\CalendarEvent;
use App\Models\Matter;
use App\Services\MMS\OutlookCalendarService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;

class CreateSingleCalendarEventAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'createSingleEvent';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('Create Single Event'))
            ->modalHeading(__('Create Single Calendar Event'))
            ->icon('heroicon-o-calendar')
            ->schema(CalendarEventForm::getFormSchema())
            ->action(function (array $data, OutlookCalendarService $outlookService, $record = null) {
                if ($record instanceof Matter) {
                    $data['matter_id'] = $record->getKey();
                }

                // To Outlook only when it is set up and asked for; a Teams
                // meeting only with it, and only when asked for.
                $toOutlook = ! empty($data['sync_to_outlook']) && $outlookService->isConfigured();
                $teams = $toOutlook && ! empty($data['is_teams_meeting']);

                $event = CalendarEvent::create([
                    ...Arr::except($data, ['sync_to_outlook', 'online_meeting_url']),
                    'type' => 'single',
                    'created_by' => Auth::id(),
                    'is_teams_meeting' => $teams,
                    'update_next_session_date' => ! empty($data['update_next_session_date']),
                ]);

                // Only when ticked (it was done whenever the field was sent).
                if (! empty($data['update_next_session_date']) && $event->matter_id) {
                    $event->matter->update(['next_session_date' => $data['start_datetime']]);
                }

                if ($toOutlook) {
                    try {
                        $outlookEvent = $outlookService->createEvent([
                            'title' => $data['title'],
                            'description' => $data['description'] ?? null,
                            'start_datetime' => $data['start_datetime'],
                            'end_datetime' => $data['end_datetime'] ?? null,
                            'location' => $data['location'] ?? null,
                            'is_teams_meeting' => $teams,
                        ]);

                        $event->update([
                            'outlook_event_id' => $outlookEvent['id'],
                            'synced_to_outlook' => true,
                            'online_meeting_url' => $teams ? OutlookCalendarService::teamsLink($outlookEvent) : null,
                        ]);
                    } catch (\Exception $e) {
                        Notification::make()
                            ->title(__('Outlook Sync Failed'))
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }

                Notification::make()
                    ->title(__('Event Created Successfully'))
                    ->success()
                    ->send();
            });
    }
}
