<?php

namespace App\Filament\Mms\Resources\Matters\Pages;

use App\Filament\Mms\Resources\CalendarEvents\Schemas\CalendarEventForm;
use App\Filament\Mms\Resources\Matters\MatterResource;
use App\Models\Matter;
use App\Services\MMS\MarketingCommissions;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditMatter extends EditRecord
{
    use OffersSessionCalendarEvent;

    protected static string $resource = MatterResource::class;

    /**
     * A new session date was saved: offer to put it on the calendar — unless
     * the matter already has an event at that moment, or the user can't make one.
     */
    protected function afterSave(): void
    {
        // Its marketers or their share changed: what they have earned, again, once (MarketingCommissions).
        app(MarketingCommissions::class)->afterChange($this->record);

        $date = $this->record->next_session_date;

        if (! $date || ! $this->record->wasChanged('next_session_date')
            || ! auth()->user()->can('CreateSingle:CalendarEvent')
            || $this->record->calendarEvents()->where('start_datetime', $date)->exists()) {
            return;
        }

        $this->mountCalendarEventModal([
            'matter_id' => $this->record->getKey(),
            'start_datetime' => $date->format('Y-m-d H:i:s'),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->sessionCalendarEventAction()
                // The matter filled in — title, place, description, and the
                // switches as a blank form has them.
                ->fillForm(fn (array $arguments) => CalendarEventForm::forMatter(
                    Matter::find($arguments['matter_id'] ?? null) ?? $this->record,
                    $arguments['start_datetime'] ?? $this->pendingSessionDate,
                )),
            ViewAction::make(),
            DeleteAction::make(),
            ForceDeleteAction::make()
                ->visible(fn ($record) => $record->trashed()),
            RestoreAction::make()
                ->visible(fn ($record) => $record->trashed()),
        ];
    }
}
