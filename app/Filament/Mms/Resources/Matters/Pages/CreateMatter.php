<?php

namespace App\Filament\Mms\Resources\Matters\Pages;

use App\Filament\Mms\Actions\Calendar\CreateSingleCalendarEventAction;
use App\Filament\Mms\Resources\CalendarEvents\Schemas\CalendarEventForm;
use App\Filament\Mms\Resources\Matters\MatterResource;
use App\Models\Matter;
use Filament\Resources\Pages\CreateRecord;
use Livewire\Attributes\On;

class CreateMatter extends CreateRecord
{
    protected static string $resource = MatterResource::class;

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

    protected function getHeaderActions(): array
    {
        return [
            CreateSingleCalendarEventAction::make('confirmCreateCalendarEvent')
                ->label(__('Create Calendar Event'))
                ->modalHeading(__('Would you like to create a calendar event for this session date?'))
                ->requiresConfirmation()
                ->color('primary')
                // The new matter filled in — title, place, description, and the
                // switches as a blank form has them.
                ->fillForm(fn (array $arguments) => ($matter = Matter::find($arguments['matter_id'] ?? null))
                    ? CalendarEventForm::forMatter($matter, $arguments['start_datetime'] ?? $this->pendingSessionDate)
                    : ['start_datetime' => $arguments['start_datetime'] ?? $this->pendingSessionDate])
                ->extraAttributes(['class' => 'hidden']),
        ];
    }
}
