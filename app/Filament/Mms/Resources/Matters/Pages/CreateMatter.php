<?php

namespace App\Filament\Mms\Resources\Matters\Pages;

use App\Filament\Mms\Resources\CalendarEvents\Schemas\CalendarEventForm;
use App\Filament\Mms\Resources\Matters\MatterResource;
use App\Filament\Mms\Resources\Matters\Schemas\MatterForm;
use App\Models\Matter;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Arr;
use Livewire\Attributes\Locked;

class CreateMatter extends CreateRecord
{
    use OffersSessionCalendarEvent;

    protected static string $resource = MatterResource::class;

    /** The matter this one is a copy of (Duplicate, ?copy=): "number/year", said under the heading. */
    #[Locked]
    public ?string $copiedFrom = null;

    /**
     * Opened by Duplicate: the form filled from the matter copied (MatterForm::copyOf()) over a new matter's
     * defaults — one the user may see, else an empty form.
     */
    protected function fillForm(): void
    {
        parent::fillForm();

        $source = filled($id = request()->query('copy')) ? MatterResource::getEloquentQuery()->find($id) : null;

        if ($source instanceof Matter) {
            $copy = MatterForm::copyOf($source);
            $this->form->fill([...$this->data, ...$copy]);
            // Its experts, parties and marketers after: a fill reads those from the new matter's own — none.
            $this->data = [...$this->data, ...Arr::only($copy, MatterForm::COPIED_LISTS)];
            $this->copiedFrom = $source->number.'/'.$source->year;
        }
    }

    public function getSubheading(): ?string
    {
        return $this->copiedFrom !== null
            ? __('Copied from matter :matter: its number, dates, next session and fees are for you to fill in.', ['matter' => $this->copiedFrom])
            : null;
    }

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
