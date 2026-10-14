<?php

namespace App\Filament\Mms\Resources\Matters\Pages;

use App\Filament\Mms\Resources\CalendarEvents\Schemas\CalendarEventForm;
use App\Filament\Mms\Resources\Matters\MatterResource;
use App\Models\Matter;
use Filament\Resources\Pages\CreateRecord;

class CreateMatter extends CreateRecord
{
    use OffersSessionCalendarEvent;

    protected static string $resource = MatterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->sessionCalendarEventAction()
                // The new matter filled in — title, place, description, and the
                // switches as a blank form has them.
                ->fillForm(fn (array $arguments) => ($matter = Matter::find($arguments['matter_id'] ?? null))
                    ? CalendarEventForm::forMatter($matter, $arguments['start_datetime'] ?? $this->pendingSessionDate)
                    : ['start_datetime' => $arguments['start_datetime'] ?? $this->pendingSessionDate])
                ->extraAttributes(['class' => 'hidden']),
        ];
    }
}
