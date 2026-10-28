<?php

namespace App\Filament\Mms\Resources\CalendarEvents\Schemas;

use App\Filament\Mms\Actions\Calendar\CalendarMatterActions;
use App\Filament\Support\SameDayMeetings;
use App\Filament\Support\TeamsAttendanceSection;
use App\Models\CalendarEvent;
use App\Models\Matter;
use App\Models\Setting;
use App\Services\MMS\Calendar\EventMatterLinker;
use App\Services\MMS\OutlookCalendarService;
use App\Support\MatterSearch;
use Carbon\Carbon;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class CalendarEventForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components(self::getFormSchema());
    }

    public static function buildDescription(Matter $matter): string
    {
        $plaintiffs = $matter->mainPartiesOnly
            ->where('type', 'plaintiff')
            ->map(fn ($mp) => $mp->party?->name)->filter()->join(', ');

        $defendants = $matter->mainPartiesOnly
            ->where('type', 'defendant')
            ->map(fn ($mp) => $mp->party?->name)->filter()->join(', ');

        $experts = $matter->expertsOnly
            ->map(fn ($mp) => $mp->party?->name)->filter()->join(', ');

        return collect([
            __('Matter').': '.$matter->year.'/'.$matter->number,
            __('Court').': '.($matter->court?->name ?? '—'),
            __('Type').': '.($matter->type?->name ?? '—'),
            $plaintiffs ? __('Plaintiffs').': '.$plaintiffs : null,
            $defendants ? __('Defendants').': '.$defendants : null,
            $experts ? __('Experts').': '.$experts : null,
        ])->filter()->join("\n");
    }

    public static function getFormSchema(?int $matterId = null): array
    {
        return [
            Section::make(__('Event Details'))
                ->schema([
                    // Found as everywhere else (MatterSearch): "639/2025" or
                    // 2025/639, the number, or words of its court, type or
                    // parties — each result with its court, type and parties.
                    Select::make('matter_id')
                        ->label(__('Matter'))
                        ->placeholder(__('Select Matter'))
                        ->searchable()
                        ->searchPrompt(__('Type the matter number (639/2025), or a court, type or party name'))
                        ->getSearchResultsUsing(fn (string $search): array => MatterSearch::options($search))
                        ->getOptionLabelUsing(fn ($value): ?string => filled($value) ? (MatterSearch::labels([$value])[(int) $value] ?? null) : null)
                        ->hidden(fn ($record) => $record instanceof Matter
                            || ($record instanceof CalendarEvent && $record->matters()->count() > 1))
                        ->disabled(fn ($record) => $record instanceof Matter)
                        ->live()
                        ->afterStateUpdated(function (?int $state, Set $set, Get $get) {
                            if (! $state) {
                                return;
                            }

                            $matter = Matter::find($state);

                            if (! $matter) {
                                return;
                            }

                            foreach (self::prefill($matter, self::willMakeTeams($get)) as $field => $value) {
                                $set($field, $value);
                            }
                        })
                        ->columnSpanFull(),
                    TextInput::make('title')
                        ->label(__('Title'))
                        ->required()
                        ->columnSpanFull(),

                    // Every matter the event is for — one or several — editable
                    // here as well as through "Link matters" on the calendar.
                    // Read and saved here, not through ->relationship(): the same form
                    // opens on a matter's page, whose record has no "matters".
                    CalendarMatterActions::mattersField(fn (Get $get): ?string => $get('title'))
                        ->getSearchResultsUsing(fn (string $search): array => MatterSearch::options($search))
                        ->getOptionLabelsUsing(fn (array $values): array => MatterSearch::labels($values))
                        ->afterStateHydrated(fn (Select $component, $record) => $record instanceof CalendarEvent
                            ? $component->state($record->matters()->pluck('matters.id')->map(fn ($id) => (int) $id)->all())
                            : null)
                        ->dehydrated(false)
                        // Its title follows the matters chosen (one or several) — and, saved, reaches Outlook.
                        ->live()
                        ->afterStateUpdated(function (?array $state, Set $set, $record): void {
                            if ($title = self::titleFor(self::mattersInOrder($state), sessions: $record instanceof CalendarEvent && $record->type === 'bulk')) {
                                $set('title', $title);
                            }
                        })
                        ->saveRelationshipsUsing(function ($record, $state): void {
                            if (! $record instanceof CalendarEvent) {
                                return;
                            }

                            $before = $record->matters()->pluck('matters.id')->map(fn ($id): int => (int) $id)->all();
                            $after = array_values(array_map('intval', $state ?? []));
                            $record->matters()->sync($after);

                            $linker = app(EventMatterLinker::class);

                            // A bulk session, or one that sets its matter's next session: the matters added take its
                            // date, as when it was made; those removed lose it.
                            if ($record->type === 'bulk' || $record->update_next_session_date) {
                                $linker->followSessionDate($record, array_values(array_diff($after, $before)), array_values(array_diff($before, $after)));
                            }

                            $linker->tidy($record->fresh());
                        })
                        // Editing an event only — not a new one opened from a matter's page,
                        // whose form is on the matter (no "matters" relation there).
                        ->visible(fn ($record): bool => $record instanceof CalendarEvent)
                        ->columnSpanFull(),

                    DateTimePicker::make('start_datetime')
                        ->label(__('Start At'))
                        ->seconds(false)
                        ->required()
                        ->timezone(config('app.timezone'))
                        ->minutesStep(15)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Set $set, ?string $state) => $set('end_datetime', $state ? Carbon::parse($state)->addHour()->format('Y-m-d H:i:s') : null)
                        ),

                    // What is already on that day.
                    SameDayMeetings::placeholder(fn ($get) => $get('start_datetime')),

                    DateTimePicker::make('end_datetime')
                        ->label(__('End At'))
                        ->seconds(false)
                        ->minutesStep(15)
                        ->timezone(config('app.timezone'))
                        ->afterOrEqual('start_datetime')
                        ->required(),

                    Toggle::make('is_all_day')
                        ->label(__('All Day'))
                        ->default(false)
                        ->columnSpanFull(),

                    TextInput::make('location')
                        ->label(__('Location'))
                        ->columnSpanFull(),

                    RichEditor::make('description')
                        ->label(__('Description'))
                        // Filament's own plain editor: a few lines, a link or a list.
                        ->toolbarButtons([['bold', 'italic', 'underline', 'strike', 'link'], ['bulletList', 'orderedList'], ['alignStart', 'alignCenter', 'alignEnd'], ['undo', 'redo']])
                        ->extraInputAttributes(['dir' => 'auto'], merge: true)
                        ->columnSpanFull(),

                    // These act when the event is made — not on editing it.
                    Toggle::make('update_next_session_date')
                        ->label(__("Update matter's next session date"))
                        ->default(true)
                        ->hidden(fn ($record) => $record instanceof CalendarEvent)
                        ->disabled(fn (Get $get) => empty($get('matter_id'))),

                    // Only with Microsoft 365 set up (Settings → Integrations).
                    Toggle::make('sync_to_outlook')
                        ->label(__('Sync to Outlook Calendar'))
                        ->default(fn (): bool => self::outlookOn())
                        ->hidden(fn ($record) => $record instanceof CalendarEvent || ! self::outlookOn())
                        ->live()
                        ->afterStateUpdated(fn (Get $get, Set $set) => self::teamsLocation($get, $set)),

                    // Asked for each time: off unless Settings → General says otherwise.
                    self::inviteToggle(
                        fn ($record, Get $get): bool => $record instanceof CalendarEvent || ! self::outlookOn() || ! $get('sync_to_outlook'),
                    ), ])->columns(2),

            Section::make(__('Online Meeting'))->schema([Toggle::make('is_teams_meeting')
                ->label(__('Create Teams Meeting'))
                ->default(true)
                // A Teams meeting is made with the Outlook event: not without it.
                ->hidden(fn ($record, Get $get) => $record instanceof CalendarEvent || ! self::outlookOn() || ! $get('sync_to_outlook'))
                ->live()
                ->afterStateUpdated(fn (Get $get, Set $set) => self::teamsLocation($get, $set))
                ->helperText(__('Creates a Microsoft Teams meeting link with this event')),

                TextInput::make('online_meeting_url')
                    ->label(__('Teams Meeting URL'))
                    ->url()
                    ->disabled()
                    ->dehydrated(false) // Ensures it doesn't try to save back to DB if disabled
                    ->placeholder(__('Generated after sync'))
                    ->suffixIcon('heroicon-o-video-camera')
                    ->visible(fn ($state) => ! empty($state))
                    ->suffixAction(
                        Action::make('openUrl')
                            ->icon('heroicon-m-arrow-top-right-on-square')
                            ->tooltip(__('Join Meeting'))
                            ->url(fn ($state) => $state)
                            ->openUrlInNewTab()
                    ), ])
                ->hidden(fn ($record, Get $get) => ! ($record instanceof CalendarEvent ? filled($record->online_meeting_url) : self::outlookOn() && $get('sync_to_outlook'))),
            // Who joined its Teams call, and when each joined and left: once it is held.
            TeamsAttendanceSection::make(),
        ];
    }

    /**
     * The event's title, place and description from its matter — as when
     * the matter is picked, or the form opens on the matter's own page.
     * The place names Teams when a Teams meeting will be made.
     *
     * @return array{title: string, location: string, description: string}
     */
    public static function prefill(Matter $matter, ?bool $teams = null): array
    {
        return self::fillFor($matter, $teams);
    }

    /**
     * A new event for this matter, as the form opens: the matter, its
     * title, place and description, and the switches as a blank form has
     * them (filling the form replaces its defaults).
     *
     * @return array<string, mixed>
     */
    public static function forMatter(Matter $matter, mixed $start = null): array
    {
        return [
            'matter_id' => $matter->getKey(),
            ...self::fillFor($matter),
            'start_datetime' => $start,
            'end_datetime' => $start ? Carbon::parse($start)->addHour()->format('Y-m-d H:i:s') : null,
            'is_all_day' => false,
            'update_next_session_date' => true,
            'sync_to_outlook' => self::outlookOn(),
            'invite_people' => OutlookCalendarService::invitesPeople(),
            'is_teams_meeting' => true,
        ];
    }

    /**
     * @return array{title: string, location: string, description: string}
     */
    private static function fillFor(Matter $matter, ?bool $teams = null): array
    {
        $matter->loadMissing(['court', 'type', 'mainPartiesOnly.party', 'expertsOnly.party']);
        $courtName = $matter->court?->name ?? '';
        $teams ??= self::outlookOn();

        return [
            'title' => self::titleFor(collect([$matter])),
            'location' => $teams ? 'Microsoft Teams - '.($courtName ?: 'Microsoft Teams') : $courtName,
            'description' => self::buildDescription($matter),
        ];
    }

    /**
     * An event's title from its matters, as made and as their choice changes: one matter "2025/639 — court —
     * type"; several — or a bulk event's (court sessions together), one or more — "639/2025, 640/2025 (court)
     * (type) (جلسات المحكمة)". Null with none: the title is left as it is.
     *
     * @param  Collection<int, Matter>  $matters  their court and type loaded
     */
    public static function titleFor(Collection $matters, bool $sessions = false): ?string
    {
        if ($matters->isEmpty()) {
            return null;
        }

        if ($matters->count() === 1 && ! $sessions) {
            $matter = $matters->first();

            return $matter->year.'/'.$matter->number.' — '.($matter->court?->name ?? '').' — '.($matter->type?->name ?? '');
        }

        $names = fn (string $of): string => $matters->map(fn (Matter $matter): ?string => $matter->{$of}?->name)->filter()->unique()->join('، ');

        $prefix = self::sessionsPrefix();

        return ($prefix !== '' ? $prefix.' ' : '')
            .$matters->map(fn (Matter $matter): string => $matter->number.'/'.$matter->year)->join(', ')
            .' ('.$names('court').') ('.$names('type').') (جلسات المحكمة)';
    }

    /** Setting (Settings → General → Calendar): what a court sessions event's title starts with. */
    public const SESSIONS_PREFIX = 'calendar_sessions_title_prefix';

    public static function sessionsPrefix(): string
    {
        return trim((string) Setting::get(self::SESSIONS_PREFIX, 'د.رضا'));
    }

    /**
     * The matters chosen, in the order chosen.
     *
     * @param  array<int|string>|null  $ids
     * @return Collection<int, Matter>
     */
    public static function mattersInOrder(?array $ids): Collection
    {
        $ids = array_values(array_map('intval', $ids ?? []));
        $found = Matter::query()->with(['court', 'type'])->whereIn('id', $ids)->get()->keyBy('id');

        return collect($ids)->map(fn (int $id): ?Matter => $found->get($id))->filter()->values();
    }

    /** "Send an invitation": the matter's assistant invited to the event put on Outlook (an email). */
    public static function inviteToggle(Closure $hidden): Toggle
    {
        return Toggle::make('invite_people')
            ->label(__('Send an invitation email to the matter\'s assistant'))
            ->helperText(__('Off: the event stays in the shared calendar, and only its reminders (24 hours and 1 hour before) go into the assistant\'s calendar.'))
            ->default(fn (): bool => OutlookCalendarService::invitesPeople())
            ->hidden($hidden);
    }

    private static function outlookOn(): bool
    {
        return app(OutlookCalendarService::class)->isConfigured();
    }

    /** Whether saving this form makes a Teams meeting. */
    private static function willMakeTeams(Get $get): bool
    {
        return self::outlookOn() && (bool) $get('sync_to_outlook') && (bool) $get('is_teams_meeting');
    }

    /**
     * The location names Teams exactly when a meeting will be made: the
     * "Microsoft Teams - " prefix added or taken off as the switches change.
     */
    private static function teamsLocation(Get $get, Set $set): void
    {
        $place = trim((string) Str::of((string) $get('location'))->replace('Microsoft Teams - ', '')->replace('Microsoft Teams', ''));

        $set('location', self::willMakeTeams($get) ? 'Microsoft Teams - '.($place !== '' ? $place : 'Microsoft Teams') : $place);
    }
}
