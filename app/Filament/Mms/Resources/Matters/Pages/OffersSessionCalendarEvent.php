<?php

namespace App\Filament\Mms\Resources\Matters\Pages;

use App\Filament\Mms\Actions\Calendar\CreateSingleCalendarEventAction;
use Livewire\Attributes\On;

/**
 * The create and edit pages of a matter both offer a calendar event when the
 * session date is set: the form announces it, this opens the confirmation.
 * Each page adds how the event's form is filled.
 */
trait OffersSessionCalendarEvent
{
    public $pendingSessionDate;

    #[On('mount-calendar-event-modal')]
    public function mountCalendarEventModal(array $data): void
    {
        $this->pendingSessionDate = $data['start_datetime'];
        $this->replaceMountedAction('confirmCreateCalendarEvent', [
            'matter_id' => $data['matter_id'],
            'start_datetime' => $data['start_datetime'],
        ]);
    }

    protected function sessionCalendarEventAction(): CreateSingleCalendarEventAction
    {
        return CreateSingleCalendarEventAction::make('confirmCreateCalendarEvent')
            ->label(__('Create Calendar Event'))
            ->modalHeading(__('Would you like to create a calendar event for this session date?'))
            ->requiresConfirmation()
            ->color('primary');
    }
}
