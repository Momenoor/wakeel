<?php

namespace App\Filament\Mms\Actions\Calendar;

use App\Filament\Mms\Resources\CalendarEvents\Schemas\CalendarEventForm;
use App\Filament\Support\OverlapConfirmation;
use App\Filament\Support\SameDayMeetings;
use App\Models\CalendarEvent;
use App\Models\Court;
use App\Models\Matter;
use App\Models\Type;
use App\Services\MMS\Calendar\EventMatterLinker;
use App\Services\MMS\OutlookCalendarService;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class CreateBulkCalendarEventAction extends Action
{
    use SyncsEventToOutlook;

    public static function getDefaultName(): ?string
    {
        return 'createBulkEvent';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('Create Bulk Event'))
            ->modalHeading(__('Create Bulk Calendar Event'))
            ->icon('heroicon-o-rectangle-stack')
            ->schema([
                // Read when the form opens — not on every load of the page.
                Select::make('matter_type_id')
                    ->label(__('Matter Type'))
                    ->options(fn () => Type::pluck('name', 'id'))
                    ->required()
                    ->live(),
                Select::make('court_id')
                    ->label(__('Court'))
                    ->options(fn () => Court::pluck('name', 'id'))
                    ->required()
                    // The location follows the court: its name — unless a place was typed that is not
                    // the previous court's name (or Teams', for a meeting).
                    ->afterStateUpdated(function (Set $set, Get $get, $state, $old): void {
                        // What this fill puts for a court: "<court name> remotely".
                        $placeOf = fn ($id): ?string => $id && ($name = Court::find($id)?->name) ? $name.' '.__('remotely') : null;
                        $current = trim((string) $get('location'));

                        // Only a blank location, or the one the previous court filled in, follows the court.
                        if ($current === '' || $current === $placeOf($old)) {
                            $set('location', $placeOf($state));
                        }
                    })
                    ->live(),
                Toggle::make('only_current')
                    ->label(__('Only In-Progress Matters'))
                    ->helperText(__('On: matters whose status is In progress. Off: all matters.'))
                    ->live()
                    ->default(true)
                    // The list is rebuilt, so anything ticked that is no longer shown is cleared.
                    ->afterStateUpdated(fn (Set $set) => $set('matter_ids', [])),
                CheckboxList::make('matter_ids')
                    ->label(__('Matters'))
                    ->searchable()
                    ->options(function (callable $get) {
                        $typeId = $get('matter_type_id');
                        if (! $typeId) {
                            return [];
                        }
                        $current = $get('only_current');

                        // That type's matters at that court (the court was asked
                        // for, then every court's were listed).
                        return Matter::where('type_id', $typeId)
                            // On: in progress only. Off: no condition, so all of them.
                            ->when($current, fn (Builder $query) => $query->inProgress())
                            ->when($get('court_id'), fn ($query, $courtId) => $query->where('court_id', $courtId))
                            ->get()
                            ->mapWithKeys(fn ($m) => [$m->id => "{$m->number}/{$m->year}"])
                            ->toArray();
                    })
                    ->required()
                    ->columns(2)
                    ->live(),
                // config('app.timezone'), not a hardcoded zone: this
                // picker previously said 'Asia/Dubai' while the single-event
                // form said 'Asia/Muscat'. Both share the same fixed UTC+4
                // offset today, so the mismatch produced no visible bug, but
                // it meant the app's notion of "local time" was defined in
                // two places that could drift.
                DateTimePicker::make('start_datetime')
                    ->label(__('Session Date & Time'))
                    ->required()
                    ->seconds(false)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Set $set, $state) => $set('end_datetime', Carbon::createFromDate($state)->addHour()))
                    ->timezone(config('app.timezone')),
                // What is already on that day.
                SameDayMeetings::placeholder(fn ($get) => $get('start_datetime')),
                DateTimePicker::make('end_datetime')
                    ->label(__('End Date & Time'))
                    ->seconds(false)
                    ->timezone(config('app.timezone')),
                TextInput::make('location')
                    ->label(__('Location')),
                Placeholder::make('generated_title')
                    ->label(__('Generated Title'))
                    // The title it will be given: the same as saved (CalendarEventForm::titleFor()).
                    ->content(fn (callable $get): string => CalendarEventForm::titleFor(CalendarEventForm::mattersInOrder($get('matter_ids')), sessions: true) ?? '—'),
                // Only with Microsoft 365 set up (Settings → Integrations).
                Toggle::make('sync_to_outlook')
                    ->label(__('Sync to Outlook Calendar'))
                    ->default(fn (): bool => app(OutlookCalendarService::class)->isConfigured())
                    ->visible(fn (): bool => app(OutlookCalendarService::class)->isConfigured())
                    ->live(),
                Toggle::make('is_teams_meeting')
                    ->label(__('Create Teams Meeting'))
                    ->default(false)
                    ->visible(fn (callable $get) => app(OutlookCalendarService::class)->isConfigured() && $get('sync_to_outlook')),
                // Asked for each time: off unless Settings → General says otherwise.
                CalendarEventForm::inviteToggle(fn (callable $get): bool => ! app(OutlookCalendarService::class)->isConfigured() || ! $get('sync_to_outlook')),
            ])
            ->action(function (array $data, OutlookCalendarService $outlookService) {
                // A meeting running during another asks first.
                $data = OverlapConfirmation::check(
                    $this,
                    $data,
                    filled($data['start_datetime'] ?? null) ? \Carbon\Carbon::parse($data['start_datetime'], config('app.timezone')) : null,
                    filled($data['end_datetime'] ?? null) ? \Carbon\Carbon::parse($data['end_datetime'], config('app.timezone')) : null,
                );

                $toOutlook = ! empty($data['sync_to_outlook']) && $outlookService->isConfigured();
                $teams = $toOutlook && ! empty($data['is_teams_meeting']);
                $event = CalendarEvent::create([
                    // From its matters, as when they are changed later (CalendarEventForm::titleFor()).
                    'title' => CalendarEventForm::titleFor(CalendarEventForm::mattersInOrder($data['matter_ids']), sessions: true),
                    'start_datetime' => $data['start_datetime'],
                    'end_datetime' => $data['end_datetime'] ?? null,
                    'location' => $data['location'] ?? null,
                    'type' => 'bulk',
                    'created_by' => Auth::id(),
                    'is_teams_meeting' => $teams,
                ]);

                // syncWithoutDetaching, not attach: the event may already be
                // linked to a matter its title names (CalendarEventObserver).
                $event->matters()->syncWithoutDetaching($data['matter_ids']);
                app(EventMatterLinker::class)->tidy($event->fresh());

                // Update next_session_date on ALL selected matters
                Matter::whereIn('id', $data['matter_ids'])->update(['next_session_date' => $data['start_datetime']]);

                if ($toOutlook) {
                    $this->pushToOutlook($outlookService, $event, ! empty($data['invite_people']));
                }

                Notification::make()
                    ->title(__('Bulk Events Created Successfully'))
                    ->success()
                    ->send();
            });

        OverlapConfirmation::configure($this, __('Create Bulk Calendar Event'));
    }
}
