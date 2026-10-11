<?php

namespace App\Filament\Mms\Resources\Matters\Pages;

use App\Filament\Mms\Resources\Matters\MatterResource;
use App\Filament\Mms\Resources\Matters\RelationManagers\MinutesRelationManager;
use App\Filament\Mms\Resources\Matters\Schemas\MinutesRecordForm;
use App\Models\Matter;
use App\Models\MatterMinutes;
use App\Services\MMS\Letters\MinutesService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\View;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;

/**
 * Recording a meeting, as a page in three steps (MinutesRecordForm):
 * attendees and opening, questions and answers, other items and closing.
 * Saved as it's typed for the live view (autosaveMinutes), and on Save —
 * when the attendees' ID numbers, phones and emails are kept on their
 * parties too.
 */
class RecordMinutes extends Page
{
    use InteractsWithRecord;

    protected static string $resource = MatterResource::class;

    public int $minutesId;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(int|string $record, int|string $minutes): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(auth()->user()?->can('update', $this->getRecord()), 403);

        $found = $this->getRecord()->minutes()->whereKey($minutes)->firstOrFail();
        $this->minutesId = $found->getKey();

        // Finalised: reopened first, from the minutes tab.
        if ($found->isFinal()) {
            Notification::make()->warning()->title(__('These minutes are finalised — reopen them to change them.'))->send();
            $this->redirect($this->backUrl());

            return;
        }

        $this->form->fill(MinutesRecordForm::fill($found));
    }

    public function minutes(): MatterMinutes
    {
        return MatterMinutes::with('template')->findOrFail($this->minutesId);
    }

    public function getTitle(): string
    {
        /** @var Matter $matter */
        $matter = $this->getRecord();

        return __('Minutes (:number)', ['number' => $this->minutes()->number]).' — '.$matter->number.'/'.$matter->year;
    }

    public function getBreadcrumbs(): array
    {
        return [
            MatterResource::getUrl() => MatterResource::getPluralModelLabel(),
            $this->backUrl() => $this->getRecord()->number.'/'.$this->getRecord()->year,
            __('Record the meeting'),
        ];
    }

    public function form(Schema $schema): Schema
    {
        $minutes = $this->minutes();

        return $schema
            ->statePath('data')
            ->components([
                // Saved as it's typed, for the live view the attendees watch.
                View::make('filament.mms.minutes.live-bar')->viewData(['url' => route('minutes.live', $minutes)]),
                Wizard::make(MinutesRecordForm::steps($minutes))
                    ->skippable()
                    ->persistStepInQueryString()
                    ->submitAction(new HtmlString(Blade::render(
                        '<x-filament::button wire:click="saveAndClose" icon="heroicon-o-check-circle">{{ $label }}</x-filament::button>',
                        ['label' => __('Save and close')],
                    ))),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedSchema::make('form')]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label(__('Save'))
                ->icon('heroicon-o-check')
                ->keyBindings(['mod+s'])
                ->action(fn () => $this->save()),
            Action::make('back')
                ->label(__('Back to the minutes'))
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('gray')
                ->url(fn (): string => $this->backUrl()),
        ];
    }

    /**
     * Saves what's on every step and stays on the page.
     */
    public function save(): void
    {
        $minutes = $this->minutes();

        if ($minutes->isFinal()) {
            Notification::make()->warning()->title(__('These minutes are finalised — reopen them to change them.'))->send();

            return;
        }

        MinutesService::saveRecorded($minutes, $this->form->getState());
        // Not while typing (autosave): only names saved become parties.
        MinutesService::registerAttendees($minutes = $minutes->fresh());
        MinutesService::rememberContactDetails($minutes->fresh());

        // The parties found or added, on the lines (in the order saved).
        $saved = array_values($minutes->fresh()->attendees ?? []);
        foreach (array_keys((array) ($this->data['attendees'] ?? [])) as $i => $key) {
            if (filled($saved[$i]['party_id'] ?? null) && is_array($this->data['attendees'][$key])) {
                $this->data['attendees'][$key]['party_id'] = $saved[$i]['party_id'];
            }
        }

        Notification::make()->success()->title(__('Minutes (:number) saved', ['number' => $minutes->number]))->send();
    }

    public function saveAndClose(): void
    {
        $this->save();

        $this->redirect($this->backUrl());
    }

    /**
     * What's being typed, saved for the live view — every few seconds,
     * without redrawing the page (nothing typed is disturbed).
     */
    public function autosaveMinutes(): void
    {
        $this->skipRender();

        $minutes = $this->minutes();

        if ($minutes->isFinal() || ! auth()->user()?->can('update', $this->getRecord())) {
            return;
        }

        MinutesService::saveRecorded($minutes, (array) $this->data);
    }

    /** The matter, on its minutes tab. */
    private function backUrl(): string
    {
        return MatterResource::getUrl('view', [
            'record' => $this->getRecord(),
            'relation' => array_search(MinutesRelationManager::class, MatterResource::getRelations(), true),
        ]);
    }
}
