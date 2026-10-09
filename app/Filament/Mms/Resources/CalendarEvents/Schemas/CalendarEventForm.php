<?php

namespace App\Filament\Mms\Resources\CalendarEvents\Schemas;

use App\Filament\Mms\Actions\Calendar\CalendarMatterActions;
use App\Filament\Support\RichEditorDirection;
use App\Models\CalendarEvent;
use App\Models\Matter;
use App\Services\MMS\Calendar\EventMatterLinker;
use App\Services\MMS\OutlookCalendarService;
use App\Support\MatterSearch;
use Carbon\Carbon;
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

                            $matter = Matter::with(['court', 'type', 'mainPartiesOnly.party', 'expertsOnly.party'])
                                ->find($state);

                            if (! $matter) {
                                return;
                            }

                            $courtName = $matter->court?->name ?? '';
                            $typeName = $matter->type?->name ?? '';

                            $set('title', $matter->year.'/'.$matter->number.' — '.$courtName.' — '.$typeName);
                            // "Microsoft Teams - …" only when a Teams meeting will be made.
                            $set('location', self::willMakeTeams($get) ? 'Microsoft Teams - '.($courtName ?: 'Microsoft Teams') : $courtName);
                            $set('description', self::buildDescription($matter));
                        })
                        ->columnSpanFull(),
                    TextInput::make('title')
                        ->label(__('Title'))
                        ->required()
                        ->columnSpanFull(),

                    // Every matter the event is for — one or several — editable
                    // here as well as through "Link matters" on the calendar.
                    CalendarMatterActions::mattersField(fn (Get $get): ?string => $get('title'))
                        ->relationship('matters', 'number')
                        ->getSearchResultsUsing(fn (string $search): array => MatterSearch::options($search))
                        ->getOptionLabelsUsing(fn (array $values): array => MatterSearch::labels($values))
                        ->saveRelationshipsUsing(function (CalendarEvent $record, $state): void {
                            $record->matters()->sync($state ?? []);
                            app(EventMatterLinker::class)->tidy($record->fresh());
                        })
                        ->visibleOn('edit')
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
                        ->extraInputAttributes(['dir' => 'auto'], merge: true)->tap(RichEditorDirection::apply(...))
                        ->columnSpanFull(),

                    // These act when the event is made — not on editing it.
                    Toggle::make('update_next_session_date')
                        ->label(__("Update matter's next session date"))
                        ->default(true)
                        ->hidden(fn ($record) => $record instanceof CalendarEvent)
                        ->disabled(fn (Get $get) => empty($get('matter_id'))),

                    // Only with Microsoft 365 set up (System Settings → Integrations).
                    Toggle::make('sync_to_outlook')
                        ->label(__('Sync to Outlook Calendar'))
                        ->default(fn (): bool => self::outlookOn())
                        ->hidden(fn ($record) => $record instanceof CalendarEvent || ! self::outlookOn())
                        ->live()
                        ->afterStateUpdated(fn (Get $get, Set $set) => self::teamsLocation($get, $set)), ])->columns(2),

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
                ->hidden(fn ($record, Get $get) => ! ($record instanceof CalendarEvent ? filled($record->online_meeting_url) : self::outlookOn() && $get('sync_to_outlook'))), ];
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
