<?php

namespace App\Filament\Mms\Resources\Matters\Schemas;

use App\Enums\FeeType;
use App\Enums\RequestStatus;
use App\Filament\Mms\Actions\Fee\CollectFeeAction;
use App\Filament\Mms\Actions\Request\ApproveRequestAction;
use App\Filament\Mms\Actions\Request\CreateRequestAction;
use App\Filament\Mms\Actions\Request\RejectRequestAction;
use App\Filament\Mms\Resources\CalendarEvents\CalendarEventResource;
use App\Filament\Mms\Resources\Matters\MatterResource;
use App\Filament\Mms\Resources\Matters\Pages\ViewMatter;
use App\Filament\Mms\Resources\Matters\RelationManagers\LettersRelationManager;
use App\Filament\Mms\Resources\Matters\RelationManagers\MinutesRelationManager;
use App\Helpers\FileUploadHelper;
use App\Models\CalendarEvent;
use App\Models\IncentiveAssistantLine;
use App\Models\Matter;
use App\Models\MatterOneDriveFolder;
use App\Models\Type;
use App\Services\MMS\Calendar\EventMatterLinker;
use App\Services\MMS\IncentiveCalculatorService;
use App\Services\MMS\MatterOneDriveFolders;
use App\Support\Currency;
use App\Support\ScreenPermissions;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\IconPosition;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class MatterInfolist
{
    // ── Shared helpers ────────────────────────────────────────────────────────
    private static function refreshComponent($component): void
    {
        $component->getLivewire()->dispatch('$refresh');
    }

    private static function refreshRecord($component): void
    {
        $component->getLivewire()->getRecord()->refresh();
        static::refreshComponent($component);
    }

    private static function formatType(string $state): string
    {
        return ucfirst(str_replace('_', ' ', $state));
    }

    private static function partyTypeColor(string $state): string
    {
        return match ($state) {
            'plaintiff' => 'success',
            'defendant' => 'danger',
            'implicate-litigant' => 'warning',
            default => 'gray',
        };
    }

    private static function expertTypeColor(string $state): string
    {
        return match ($state) {
            'certified' => 'success',
            'assistant' => 'info',
            'external' => 'warning',
            default => 'gray',
        };
    }

    // ── Main configure ────────────────────────────────────────────────────────

    public static function configure(Schema $schema): Schema
    {
        // A summary strip with what is looked for first, then everything
        // else in tabs — the page used to be two long columns of sections.
        // The open tab is kept in the address (?tab=), so a refresh or a
        // shared link opens the same tab.
        return $schema
            ->columns(1)
            ->components([
                static::summarySection(),
                Tabs::make('matter')
                    ->persistTabInQueryString('tab')
                    ->columnSpanFull()
                    ->tabs([
                        Tab::make(__('Overview'))
                            ->icon('heroicon-o-document-text')
                            ->visible(fn (): bool => ScreenPermissions::can(ScreenPermissions::MATTER_OVERVIEW_TAB))
                            ->columns(2)
                            ->schema([
                                Grid::make(1)->columnSpan(1)->schema([
                                    static::identitySection(),
                                    static::classificationSection(),
                                    static::datesSection(),
                                ]),
                                Grid::make(1)->columnSpan(1)->schema([
                                    static::expertsSection(),
                                    static::partiesSection(),
                                ]),
                            ]),
                        Tab::make(__('Sessions & Events'))
                            ->icon('heroicon-o-calendar-days')
                            ->visible(fn (): bool => ScreenPermissions::can(ScreenPermissions::MATTER_SESSIONS_TAB))
                            ->badge(fn ($record) => $record ? ($record->linkedCalendarEvents()->where('start_datetime', '>=', now()->startOfDay())->count() ?: null) : null)
                            ->schema([static::eventsSection()]),
                        Tab::make(__('Fees & Incentive'))
                            ->icon('heroicon-o-banknotes')
                            ->visible(fn (): bool => ScreenPermissions::can(ScreenPermissions::MATTER_FEES_TAB))
                            ->schema([
                                static::feesSection(),
                                static::incentiveSection(),
                            ]),
                        Tab::make(__('Requests & Notes'))
                            ->icon('heroicon-o-chat-bubble-left-right')
                            ->visible(fn (): bool => ScreenPermissions::can(ScreenPermissions::MATTER_REQUESTS_TAB))
                            ->badge(fn ($record) => $record ? ($record->requests()->where('status', RequestStatus::PENDING->value)->count() ?: null) : null)
                            ->badgeColor('warning')
                            ->schema([
                                static::requestsSection(),
                                static::notesSection(),
                            ]),
                        Tab::make(__('Files'))
                            ->icon('heroicon-o-paper-clip')
                            ->visible(fn (): bool => ScreenPermissions::can(ScreenPermissions::MATTER_FILES_TAB))
                            ->schema([
                                static::attachmentsSection(),
                                static::oneDriveSection(),
                            ]),
                        // The letters table, in its own tab rather than below
                        // the page — the same relation manager, with its
                        // Issue letter action.
                        Tab::make(__('Letters'))
                            ->icon('heroicon-o-envelope')
                            ->visible(fn (): bool => ScreenPermissions::can(ScreenPermissions::MATTER_LETTERS_TAB)
                                && ScreenPermissions::can(ScreenPermissions::MATTER_LETTERS))
                            // Counted once (the badge is asked for more than once).
                            ->badge(fn ($record) => $record ? (($record->letters_count ?? $record->loadCount('letters')->letters_count) ?: null) : null)
                            ->schema([
                                Livewire::make(LettersRelationManager::class, fn ($record) => [
                                    'ownerRecord' => $record,
                                    'pageClass' => ViewMatter::class,
                                ])->key('matter-letters'),
                            ]),
                        // Meeting minutes (محاضر): prepared, filled in at the
                        // meeting, finalised.
                        Tab::make(__('Meeting minutes'))
                            ->icon('heroicon-o-clipboard-document-list')
                            ->visible(fn (): bool => ScreenPermissions::can(ScreenPermissions::MATTER_MINUTES_TAB)
                                && ScreenPermissions::can(ScreenPermissions::MATTER_MINUTES))
                            ->badge(fn ($record) => $record ? (($record->minutes_count ?? $record->loadCount('minutes')->minutes_count) ?: null) : null)
                            ->schema([
                                Livewire::make(MinutesRelationManager::class, fn ($record) => [
                                    'ownerRecord' => $record,
                                    'pageClass' => ViewMatter::class,
                                ])->key('matter-minutes'),
                            ]),
                    ]),
            ]);
    }

    // ── Summary ───────────────────────────────────────────────────────────────

    /**
     * The facts people open a matter for, always in view above the tabs.
     */
    private static function summarySection(): Section
    {
        return Section::make()
            ->columns(['default' => 2, 'md' => 3, 'xl' => 6])
            ->schema([
                TextEntry::make('reference_summary')
                    ->label(__('Matter'))
                    ->state(fn ($record) => $record ? $record->number.'/'.$record->year : null)
                    ->weight(FontWeight::Bold)
                    ->size(TextSize::Large),
                TextEntry::make('status_summary')
                    ->label(__('Status'))
                    ->state(fn ($record) => $record?->status?->getLabel())
                    ->badge()
                    ->color(fn ($record) => $record?->status?->getColor() ?? 'gray'),
                TextEntry::make('court.name')
                    ->label(__('Court'))
                    ->icon('heroicon-o-building-library')
                    ->placeholder('—'),
                TextEntry::make('type.name')
                    ->label(__('Type'))
                    ->icon('heroicon-o-rectangle-stack')
                    ->placeholder('—'),
                TextEntry::make('next_session_summary')
                    ->label(__('Next Session'))
                    ->icon('heroicon-o-calendar')
                    ->state(function ($record) {
                        $next = $record?->linkedCalendarEvents()->where('start_datetime', '>=', now())->orderBy('start_datetime')->value('start_datetime');

                        return $next ?? $record?->next_session_date;
                    })
                    ->dateTime('D d/m/Y g:i A')
                    ->placeholder('—')
                    ->color('primary'),
                TextEntry::make('assistants_summary')
                    ->label(__('Assistants'))
                    ->icon('heroicon-o-user-group')
                    ->state(fn ($record) => $record?->assistantsOnly()->with('party:id,name')->get()->pluck('party.name')->filter()->implode('، ') ?: null)
                    ->placeholder('—'),
            ]);
    }

    // ── Sessions & events ─────────────────────────────────────────────────────

    /**
     * Every calendar event for the matter — from Outlook or made in Wakeel,
     * linked as its only matter or among several — upcoming then past.
     */
    private static function eventsSection(): Section
    {
        return Section::make(__('Sessions & Events'))
            ->key('matter-events')
            ->icon('heroicon-o-calendar-days')
            ->description(__('Court sessions and meetings from the calendar. Events whose title names this matter (e.g. 639/2025) are linked automatically.'))
            ->headerActions([static::linkEventAction()])
            ->schema([
                TextEntry::make('events_list')
                    ->hiddenLabel()
                    ->state(function ($record) {
                        if (! $record) {
                            return null;
                        }

                        $today = now()->startOfDay();
                        $events = fn () => $record->linkedCalendarEvents()->with('matters:id');

                        return new HtmlString(view('filament.mms.matters.events', [
                            'upcoming' => $events()->where('start_datetime', '>=', $today)->orderBy('start_datetime')->get(),
                            'past' => $events()->where('start_datetime', '<', $today)->orderByDesc('start_datetime')->limit(30)->get(),
                            'pastTotal' => $events()->where('start_datetime', '<', $today)->count(),
                            // Each event opens on the Calendar, in its own
                            // details window — for those who may see it.
                            'eventUrl' => CalendarEventResource::canViewAny()
                                ? fn (CalendarEvent $event): string => CalendarEventResource::getUrl('index', [
                                    'tableAction' => 'view',
                                    'tableActionRecord' => $event->getKey(),
                                    // The calendar lists upcoming events by default.
                                    ...($event->start_datetime?->isPast() ? ['filters' => ['upcoming' => ['isActive' => false]]] : []),
                                ])
                                : fn (): ?string => null,
                        ])->render());
                    })
                    ->html(),
            ]);
    }

    /**
     * Links an existing calendar event to this matter — found by its title
     * or date.
     */
    private static function linkEventAction(): Action
    {
        return Action::make('linkCalendarEvent')
            ->label(__('Link event'))
            ->icon('heroicon-o-link')
            ->size('sm')
            ->visible(fn ($record) => auth()->user()?->can('update', $record))
            ->schema([
                Select::make('event_id')
                    ->label(__('Event'))
                    ->helperText(__('Search by title, or a date like 29/09/2026.'))
                    ->searchable()
                    ->required()
                    ->getOptionLabelUsing(fn ($value): ?string => ($event = CalendarEvent::find($value))
                        ? $event->start_datetime?->format('d/m/Y g:i A').' — '.$event->title
                        : null)
                    ->getSearchResultsUsing(function (string $search): array {
                        $query = CalendarEvent::query()->orderByDesc('start_datetime')->limit(30);
                        $day = rescue(fn () => Carbon::createFromFormat('d/m/Y', trim($search)), null, false);

                        $day
                            ? $query->whereDate('start_datetime', $day)
                            : $query->where('title', 'like', '%'.$search.'%');

                        return $query->get()->mapWithKeys(fn (CalendarEvent $event) => [
                            $event->id => $event->start_datetime?->format('d/m/Y g:i A').' — '.$event->title,
                        ])->all();
                    }),
            ])
            ->action(function (array $data, $record, $component) {
                $event = CalendarEvent::find($data['event_id']);

                if ($event) {
                    $event->matters()->syncWithoutDetaching([$record->getKey()]);
                    app(EventMatterLinker::class)->tidy($event->fresh());
                }

                Notification::make()->title(__('Event linked'))->success()->send();
                static::refreshComponent($component);
            });
    }

    // ── Left column sections ──────────────────────────────────────────────────

    private static function identitySection(): Section
    {
        return Section::make(__('Basic Information'))
            ->icon('heroicon-o-hashtag')
            ->columns(2)
            ->schema([
                TextEntry::make('year')->label(__('Year')),
                TextEntry::make('number')->label(__('Number')),
                TextEntry::make('status')->label(__('Status'))
                    ->formatStateUsing(fn ($state) => $state->getLabel())
                    ->badge()->columnSpan(2),
                TextEntry::make('collection_status')->label(__('Collection'))->badge(),
                IconEntry::make('has_court_penalty')->label(__('Has Court Penalty'))->boolean(),
                TextEntry::make('commissioning')->label(__('Commissioning'))
                    ->formatStateUsing(fn ($state) => __($state->getLabel())),
                IconEntry::make('is_office_work')
                    ->label(__('Is Office Work'))
                    ->default(false)
                    ->boolean(),
                TextEntry::make('review_count')->label(__('Review Count')),
                IconEntry::make('has_substantive_changes')->label(__('Has Substantive Changes'))->boolean(),
                TextEntry::make('parent_id')
                    ->label(__('Parent Matter'))
                    ->numeric()
                    ->placeholder('—')
                    ->formatStateUsing(fn ($state, $record) => $state
                        ? __('Matter')." #{$record->number}/{$record->year} [#{$state}]"
                        : null
                    )
                    ->url(fn ($state) => $state ? MatterResource::getUrl('view', ['record' => $state]) : null)
                    ->icon('heroicon-o-link')
                    ->color('primary')
                    ->iconPosition(IconPosition::After)
                    ->openUrlInNewTab(),
            ]);
    }

    private static function classificationSection(): Section
    {
        return Section::make(__('Classification'))
            ->icon('heroicon-o-tag')
            ->columns(2)
            ->schema([
                TextEntry::make('court.name')->label(__('Court'))->icon('heroicon-o-building-library'),
                TextEntry::make('type.name')->label(__('Type'))->icon('heroicon-o-rectangle-stack'),
                TextEntry::make('level')->label(__('Level'))->badge(),
                TextEntry::make('difficulty')->label(__('Difficulty'))->badge(),
                Group::make()
                    ->schema(function ($record) {
                        if (! $record || ! $record->type_id) {
                            return [];
                        }

                        $type = $record->type;
                        if (! $type) {
                            return [];
                        }

                        $fields = $type->fieldDefinitions;
                        $entries = [];

                        foreach ($fields as $field) {
                            $value = $record->custom_fields[$field->label] ?? null;

                            if ($value === null) {
                                continue;
                            }

                            $entry = TextEntry::make("custom_fields.{$field->label}")
                                ->label($field->label);

                            if ($field->type === 'select_input') {
                                $entry->formatStateUsing(fn ($state) => $field->options[$state] ?? $state);
                            }

                            $entries[] = $entry;
                        }

                        return $entries;
                    })
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }

    private static function datesSection(): Section
    {
        return Section::make(__('Key Dates'))
            ->icon('heroicon-o-calendar-days')
            ->columns(2)
            ->schema([
                TextEntry::make('received_at')->label(__('Court Assigning Date'))->date()
                    ->icon('heroicon-o-arrow-down-tray')->placeholder('—'),
                TextEntry::make('next_session_date')->label(__('Next Session'))->dateTime()
                    ->icon('heroicon-o-calendar')->placeholder('—'),
                TextEntry::make('distributed_at')->label(__('Assistant Assigning Date'))->date()
                    ->icon('heroicon-o-arrow-down-tray')->placeholder('—'),
                TextEntry::make('initial_report_at')->label(__('Initial Report'))->date()
                    ->icon('heroicon-o-document-check')->placeholder('—'),
                TextEntry::make('final_report_at')->label(__('Final Report'))->date()
                    ->icon('heroicon-o-paper-airplane')->placeholder('—'),
                TextEntry::make('final_report_memo_date')->label(__('Final Report Memo Date'))
                    ->date()->placeholder('—'),
                TextEntry::make('created_at')->label(__('Created'))->date()->placeholder('—'),
                TextEntry::make('updated_at')->label(__('Updated'))->date()->placeholder('—'),
            ]);
    }

    private static function requestsSection(): Section
    {
        return Section::make(__('Requests'))
            ->icon('heroicon-o-chat-bubble-left-right')
            ->headerActions([static::addRequestAction()])
            ->schema([
                RepeatableEntry::make('requests')
                    ->hiddenLabel()
                    ->columns(4)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('id')->color('primary')->icon(Heroicon::Hashtag)->default(0)->label(__('Number'))->url(fn ($record) => route('filament.mms.resources.matter-requests.view', $record)),
                        TextEntry::make('requestBy.display_name')
                            ->label(__('Requester'))
                            ->weight(FontWeight::SemiBold),
                        TextEntry::make('type')
                            ->label(__('Type'))
                            ->badge()
                            ->color('info'),
                        TextEntry::make('status')
                            ->label(__('Status'))
                            ->badge(),
                        TextEntry::make('created_at')
                            ->label(__('Created At'))
                            ->dateTime()
                            ->since()
                            ->icon('heroicon-o-calendar'),

                        TextEntry::make('comment')->label(__('Comment'))->columnSpanFull(),
                        TextEntry::make('approvedBy.display_name')
                            ->label(__('Reviewed By'))
                            ->visible(fn ($record) => $record->status !== RequestStatus::PENDING && $record->status !== RequestStatus::DISPUTED),
                        TextEntry::make('approved_at')
                            ->label(__('Date'))
                            ->icon('heroicon-o-calendar')
                            ->dateTime()
                            ->since()
                            ->visible(fn ($record) => $record->status !== RequestStatus::PENDING && $record->status !== RequestStatus::DISPUTED),
                        TextEntry::make('approved_comment')
                            ->label(__('Reviewer Comment'))
                            ->columnSpanFull()
                            ->visible(fn ($record) => ! empty($record->approved_comment)),
                        RepeatableEntry::make('attachments')
                            ->label(__('Attachments'))
                            ->columnSpanFull()
                            ->visible(fn ($record) => $record->attachments->isNotEmpty())
                            ->schema([
                                TextEntry::make('name')
                                    ->hiddenLabel()
                                    ->icon('heroicon-o-paper-clip')
                                    ->url(fn ($record) => Storage::disk('public')->url($record->path))
                                    ->openUrlInNewTab()
                                    ->color('primary'),
                            ]),
                        Actions::make([
                            static::approveRequestAction(),
                            static::rejectRequestAction(),
                        ])
                            ->columnSpanFull()
                            ->alignEnd(),
                    ]),
            ]);
    }

    private static function notesSection(): Section
    {
        return Section::make(__('Notes'))
            ->icon('heroicon-o-chat-bubble-bottom-center-text')
            ->headerActions([static::addNoteAction()])
            ->schema([
                RepeatableEntry::make('notes')
                    ->hiddenLabel()
                    ->columns(2)
                    ->columnSpanFull()
                    ->visible(fn ($record) => $record?->notes?->isNotEmpty())
                    ->schema([
                        TextEntry::make('text')->label(__('Note'))->columnSpanFull(),
                        TextEntry::make('user.display_name')->label(__('By'))
                            ->icon('heroicon-o-user')->size(TextSize::ExtraSmall),
                        TextEntry::make('datetime')->label(__('Date'))
                            ->since()
                            ->dateTime()->icon('heroicon-o-clock')->size(TextSize::ExtraSmall),
                        Actions::make([
                            static::editNoteAction(),
                            static::deleteNoteAction(),
                        ])->columnSpanFull(),
                    ]),
            ]);
    }

    // ── Right column sections ─────────────────────────────────────────────────

    private static function expertsSection(): Section
    {
        return Section::make(__('Experts'))
            ->icon('heroicon-o-academic-cap')
            ->description(__('Certified experts, assistants, and external appointments'))
            ->schema([
                RepeatableEntry::make('indexedExperts')
                    ->hiddenLabel()
                    ->columns(7)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('type')
                            ->label(__('Role'))
                            ->formatStateUsing(fn ($state) => __($state
                                ? ucfirst(str_replace('-', ' ', $state)) : ''))
                            ->badge()
                            ->color(fn ($state) => static::expertTypeColor($state)),
                        TextEntry::make('role_index')->label('#')->badge()->color('gray'),
                        TextEntry::make('party.name')
                            ->label(__('Name'))
                            ->icon('heroicon-o-user-circle')
                            ->weight(FontWeight::SemiBold)
                            ->columnSpan(5),
                    ]),
            ]);
    }

    private static function partiesSection(): Section
    {
        return Section::make(__('Parties'))
            ->icon('heroicon-o-scale')
            ->description(__('Plaintiffs, defendants, and litigants with their representatives'))
            ->schema([
                RepeatableEntry::make('indexedParties')
                    ->hiddenLabel()
                    ->columns(7)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('type')
                            ->label(__('Role'))
                            // As the matter's type calls the side (المتنازع, الطاعن …).
                            // The matter on screen's type: each party row loaded its matter and
                            // type again.
                            ->formatStateUsing(fn ($state, $record, $livewire) => $state ? Type::sideLabel((method_exists($livewire, 'getRecord') ? $livewire->getRecord() : $record?->matter)?->type, $state) : '')
                            ->badge()
                            ->color(fn ($state) => static::partyTypeColor($state)),
                        TextEntry::make('role_index')
                            ->label('#')
                            ->badge()
                            ->color('gray'),
                        TextEntry::make('party.name')
                            ->label(__('Name'))
                            ->icon('heroicon-o-user-circle')
                            ->weight(FontWeight::SemiBold)
                            ->columnSpan(5)
                            ->grow(),
                        RepeatableEntry::make('representatives')
                            ->label(__('Representatives'))
                            ->columns(1)
                            ->columnSpanFull()
                            ->visible(fn ($record) => $record?->representatives?->isNotEmpty())
                            ->schema([
                                TextEntry::make('party.name')->label(__('Name'))
                                    ->icon('heroicon-o-user')->columnSpan(3),
                            ]),
                    ]),
            ]);
    }

    private static function feesSection(): Section
    {
        return Section::make(__('Fees & Collections'))
            ->icon('heroicon-o-banknotes')
            ->headerActions([
                Action::make('create_fee')
                    ->visible(fn ($record) => auth()->user()->can('CreateFee:Matter'))
                    ->label(__('Add Fee'))
                    ->icon('heroicon-o-plus')
                    ->schema([
                        Select::make('type')
                            ->label(__('Type'))
                            ->options(FeeType::class)
                            ->afterStateUpdated(fn ($state, $component) => $state?->isNegative() ? $component->getLivewire()->refresh() : null)
                            ->live(onBlur: true)
                            ->required(),
                        TextInput::make('amount')
                            ->label(__('Amount'))
                            ->numeric()
                            ->prefix(fn (Get $get) => $get('type')?->isNegative() ? '-' : '+')
                            ->live()
                            ->required(),
                        Textarea::make('description')
                            ->label(__('Description')),
                    ])->action(function (array $data, $record, $component) {
                        $record->fees()->create($data);
                        $record->updateCollectionStatus();
                        $record->refresh();
                        $component->getLivewire()->refresh();
                    }),
            ])
            ->schema([
                RepeatableEntry::make('fees')
                    ->columns(5)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('amount')
                            ->label(__('Fee Amount'))
                            ->aed()
                            ->weight(FontWeight::SemiBold)
                            ->icon('heroicon-o-banknotes')
                            ->color(fn ($state, Get $get) => $get('type')?->isNegative() ? 'danger' : null),
                        TextEntry::make('collected_amount')
                            ->label(__('Collected'))
                            ->aed()
                            ->icon('heroicon-o-check-circle')
                            ->getStateUsing(fn ($record) => $record?->allocations?->sum('amount') ?? 0)
                            ->color(fn ($state, $record) => match (true) {
                                (float) $state >= (float) ($record?->amount ?? 0) => 'success',
                                (float) $state > 0 => 'warning',
                                default => 'danger',
                            }),
                        TextEntry::make('type')->badge(),

                        TextEntry::make('date')->label(__('Date'))
                            ->date()->icon('heroicon-o-calendar'),

                        Actions::make([
                            static::collectFeeAction(),
                            static::editFeeAction(),
                            static::deleteFeeAction(),
                        ])->alignEnd(),
                        TextEntry::make('description')->label(__('Description'))
                            ->placeholder('—')->columnSpan(2),
                        RepeatableEntry::make('allocations')
                            ->label(__('Payment History'))
                            ->columns(4)
                            ->columnSpanFull()
                            ->visible(fn ($record) => $record?->allocations?->isNotEmpty())
                            ->schema([
                                TextEntry::make('amount')->label(__('Amount'))
                                    ->aed()->weight(FontWeight::SemiBold)->color('success'),
                                TextEntry::make('date')->label(__('Date'))->date(),
                                TextEntry::make('description')->label(__('Notes'))
                                    ->placeholder('—')->columnSpan(2),
                                Actions::make([
                                    static::editAllocationAction(),
                                    static::deleteAllocationAction(),
                                ])->columnSpanFull(),
                            ]),
                    ]),
            ]);
    }

    private static function incentiveSection(): Section
    {
        return Section::make(__('Incentive'))
            ->icon('heroicon-o-calculator')
            ->description(__('Finalized incentive calculations for this matter'))
            ->visible(fn ($record) => $record && static::visibleIncentiveLines($record)->isNotEmpty())
            ->schema(function ($record) {
                if (! $record) {
                    return [];
                }

                $service = app(IncentiveCalculatorService::class);

                return static::visibleIncentiveLines($record)
                    ->groupBy(fn (IncentiveAssistantLine $al) => $al->incentiveLine->incentive_calculation_id)
                    ->map(function (Collection $assistantLines, int $calculationId) use ($record, $service) {
                        // A matter with several fees has one line per fee in a
                        // calculation; the assistants hang off the first.
                        $feeLines = $record->incentiveLines()->where('incentive_calculation_id', $calculationId)->with('deductions')->get();
                        $line = $assistantLines->first()->incentiveLine;

                        $rows = $assistantLines->map(function (IncentiveAssistantLine $al) use ($service, $calculationId) {
                            $fixed = (float) ($service->fixedDeductionByLine($calculationId, $al->party_id)[$al->id] ?? 0);

                            return [
                                'name' => $al->party?->name ?? '—',
                                'override' => $al->percentage_override !== null,
                                'share' => (float) $al->share_amount,
                                'extra' => (float) $al->extra_amount,
                                'extra_pct' => (float) $al->extra_percentage,
                                'penalty' => (float) $al->minimum_penalty_amount,
                                'penalty_pct' => (float) $al->minimum_penalty_pct,
                                'fixed' => $fixed,
                                'net' => max(0.0, round((float) $al->total_amount - $fixed, 2)),
                            ];
                        })->values()->all();

                        $deductionTypes = $feeLines->flatMap->deductions
                            ->map(fn ($d) => '−'.$d->percentage.'% '.__($d->type))
                            ->unique()->implode(' · ');

                        return Group::make()
                            ->columns(6)
                            ->columnSpanFull()
                            ->schema([
                                TextEntry::make("incentive_{$calculationId}_calc")
                                    ->label(__('Calculation'))
                                    ->state($line->calculation?->name ?? '—'),
                                TextEntry::make("incentive_{$calculationId}_difficulty")
                                    ->label(__('Difficulty'))
                                    ->badge()
                                    ->state($line->difficulty ?: '—'),
                                TextEntry::make("incentive_{$calculationId}_days")
                                    ->label(__('Days'))
                                    ->state($line->completion_days ?? '—'),
                                TextEntry::make("incentive_{$calculationId}_rate")
                                    ->label(__('Rate %'))
                                    ->state($line->effective_percentage.'%'),
                                TextEntry::make("incentive_{$calculationId}_deductions")
                                    ->label(__('Deductions'))
                                    ->color('danger')
                                    ->state($line->total_deduction_pct > 0 ? '-'.$line->total_deduction_pct.'%' : '—')
                                    ->helperText($deductionTypes ?: null),
                                // These two figures are DIFFERENT stages of the
                                // same calculation, not the same number twice:
                                // the base is the office's fee × rate −
                                // deductions; each assistant's figures below
                                // apply their own rate and any monthly bonus,
                                // shortfall penalty or fixed deduction.
                                TextEntry::make("incentive_{$calculationId}_net")
                                    ->label(__('Incentive Base'))
                                    ->helperText(__('The fee at this rate, before the assistant rate is applied — not the amount paid out.'))
                                    ->state(Currency::label(number_format((float) $feeLines->sum('net_amount'), 2).' AED')),
                                TextEntry::make("incentive_{$calculationId}_assistants")
                                    ->label(__('Paid to Assistants'))
                                    ->columnSpanFull()
                                    ->helperText(__('Each assistant\'s rate on the incentive base above, plus any monthly bonus, less any shortfall penalty and their fixed deduction (spread over their matters) — so it will not equal the base.'))
                                    ->state(new HtmlString(view('filament.mms.matters.incentive-breakdown', ['rows' => $rows])->render()))
                                    ->html(),
                            ]);
                    })->values()->all();
            });
    }

    /**
     * The finalized assistant lines on this matter the viewer may see: every
     * assistant's for a super admin, otherwise only the viewer's own.
     *
     * @return Collection<int, IncentiveAssistantLine>
     */
    private static function visibleIncentiveLines($record): Collection
    {
        $user = auth()->user();
        $seesAll = $user?->hasRole('super-admin') ?? false;
        $ownPartyId = $user?->party?->id;

        if (! $seesAll && ! $ownPartyId) {
            return collect();
        }

        return IncentiveAssistantLine::query()
            ->whereHas('incentiveLine', fn ($q) => $q->where('matter_id', $record->getKey())
                ->whereHas('calculation', fn ($q) => $q->where('status', 'finalized')))
            ->when(! $seesAll, fn ($q) => $q->where('party_id', $ownPartyId))
            ->with(['party', 'incentiveLine.calculation'])
            ->orderBy('id')
            ->get();
    }

    /**
     * The matter's folders in its assistants' OneDrive: a super admin sees
     * every assistant's, anyone else only their own. "Create folders" makes
     * any missing or failed one again — folders already made stay as they
     * are.
     */
    private static function oneDriveSection(): Section
    {
        $visible = function ($record): Collection {
            $user = auth()->user();

            return $record->oneDriveFolders()
                ->with('party')
                ->when(! ($user?->hasRole('super-admin') ?? false), fn ($q) => $q->where('party_id', $user?->party?->id ?? 0))
                ->get();
        };

        return Section::make(__('OneDrive'))
            ->icon('heroicon-o-cloud')
            ->collapsible()
            ->visible(fn ($record) => $record && (
                $visible($record)->isNotEmpty()
                || (MatterOneDriveFolders::enabled() && auth()->user()?->can('update', $record) && $record->assistantsOnly()->exists())
            ))
            ->headerActions([
                Action::make('createOneDriveFolders')
                    ->label(__('Create folders'))
                    ->icon('heroicon-o-folder-plus')
                    ->size('sm')
                    ->visible(fn ($record) => auth()->user()?->can('update', $record) && $record->assistantsOnly()->exists())
                    ->requiresConfirmation()
                    ->modalDescription(__('Makes the folder in each assistant\'s OneDrive where it is missing or failed. Folders already made are not changed.'))
                    ->action(function ($record) {
                        $queued = $record->assistantsOnly()->pluck('party_id')->unique()
                            ->map(fn ($partyId) => MatterOneDriveFolders::queue($record, (int) $partyId))
                            ->filter(fn ($folder) => ! $folder->isCreated())
                            ->count();

                        Notification::make()
                            ->title($queued ? __(':count folder(s) queued — they appear here within a minute.', ['count' => $queued]) : __('Every assistant already has the folder.'))
                            ->success()
                            ->send();
                    }),
            ])
            ->schema(fn ($record) => $record ? [
                TextEntry::make('onedrive_folders')
                    ->hiddenLabel()
                    ->state(function () use ($record, $visible) {
                        $rows = $visible($record);

                        if ($rows->isEmpty()) {
                            return __('No folders yet.');
                        }

                        return new HtmlString($rows->map(function (MatterOneDriveFolder $folder): string {
                            $name = e($folder->party?->name ?? '—');
                            $label = e($folder->folder_name);

                            return match ($folder->status) {
                                MatterOneDriveFolder::CREATED => "<div>{$name}: <a href=\"".e($folder->web_url).'" target="_blank" rel="noopener" style="text-decoration: underline;">'.$label.'</a></div>',
                                MatterOneDriveFolder::FAILED => "<div>{$name}: <span style=\"color: rgb(220 38 38);\">".e(__('Failed')).' — '.e((string) $folder->error).'</span></div>',
                                default => "<div>{$name}: <span style=\"opacity: .7;\">".e(__('Creating…')).' '.$label.'</span></div>',
                            };
                        })->implode(''));
                    })
                    ->html(),
            ] : []);
    }

    private static function attachmentsSection(): Section
    {
        return Section::make(__('Attachments'))
            ->icon('heroicon-o-paper-clip')
            ->headerActions([static::addAttachmentsAction()])
            ->schema([
                RepeatableEntry::make('attachments')
                    ->hiddenLabel()
                    ->columns(5)
                    ->columnSpanFull()
                    ->visible(fn ($record) => $record?->attachments?->isNotEmpty())
                    ->schema([
                        TextEntry::make('name')
                            ->label(__('Name'))
                            ->weight(FontWeight::SemiBold)
                            ->columnSpan(4)
                            ->alignStart()
                            ->icon('heroicon-o-document-text')
                            ->url(fn ($record) => Storage::disk('public')->url($record->path))
                            ->openUrlInNewTab()
                            ->alignJustify(),
                        Actions::make([
                            static::downloadAttachmentAction(),
                            static::deleteAttachmentAction(),
                        ])->alignEnd(),
                        TextEntry::make('type')
                            ->label(__('Type'))
                            ->badge()
                            ->color('info')
                            ->formatStateUsing(fn ($state) => $state
                                ? __($state
                                        |> __(...)
                                        |> (fn ($x) => str_replace('_', ' ', $x))
                                        |> ucfirst(...)) : ''),
                        TextEntry::make('extension')->label(__('Extension'))->badge()->color('gray'),
                        TextEntry::make('size')
                            ->label(__('Size'))
                            ->formatStateUsing(fn ($state) => number_format($state / (1024 * 1024), 2).' MB'),
                        TextEntry::make('created_at')
                            ->label(__('Date'))
                            ->dateTime(format: 'M, d Y - H:i A')
                            ->columnSpan(2)
                            ->icon('heroicon-o-calendar'),
                    ]),
            ]);
    }

    // ── Actions ───────────────────────────────────────────────────────────────

    private static function addRequestAction(): Action
    {
        return CreateRequestAction::make();

    }

    private static function approveRequestAction(): Action
    {
        return ApproveRequestAction::make();
    }

    private static function rejectRequestAction(): Action
    {
        return RejectRequestAction::make();
    }

    private static function addNoteAction(): Action
    {
        return Action::make('addNote')
            ->label(__('Add Note'))
            ->icon('heroicon-o-plus')
            ->visible(fn ($record) => auth()->user()->can('CreateNote:Matter'))
            ->modalHeading(__('Add New Note'))
            ->schema([
                Textarea::make('text')->label(__('Content'))->required()->rows(3),
            ])
            ->action(function (array $data, $record, $component) {
                $record->notes()->create([
                    'text' => $data['text'],
                    'user_id' => auth()->id(),
                    'datetime' => now(),
                ]);

                $record->refresh();
                $record->unsetRelation('notes');
                static::refreshComponent($component);
            })
            ->successNotificationTitle(__('Note added successfully.'));
    }

    private static function editNoteAction(): Action
    {
        return Action::make('editNote')
            ->label(__('Edit'))
            ->iconButton()
            ->icon('heroicon-o-pencil')
            ->visible(fn ($record) => auth()->user()->can('UpdateNote:Matter'))
            ->modalHeading(__('Edit Note'))
            ->schema([
                Textarea::make('text')->label(__('Content'))->required()->rows(3),
            ])
            ->fillForm(fn ($record) => ['text' => $record->text])
            ->action(function (array $data, $record, $component) {
                $record->update(['text' => $data['text']]);
                $record->refresh();
                static::refreshComponent($component);
            });
    }

    private static function deleteNoteAction(): Action
    {
        return Action::make('deleteNote')
            ->label(__('Delete'))
            ->iconButton()
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->visible(fn ($record) => auth()->user()->can('DeleteNote:Matter'))
            ->requiresConfirmation()
            ->action(function ($record, $component) {
                $record->delete();
                static::refreshComponent($component);
            });
    }

    private static function collectFeeAction(): Action
    {
        return CollectFeeAction::make()->after(function ($component) {
            static::refreshRecord($component);
        });

    }

    private static function editFeeAction(): Action
    {
        return Action::make('editFee')
            ->label(__('Edit'))
            ->iconButton()
            ->icon('heroicon-o-pencil')
            ->visible(fn ($record) => auth()->user()->can('UpdateFee:Matter'))
            ->modalHeading(__('Edit Fee'))
            ->schema([
                TextInput::make('amount')->label(__('Fee Amount'))
                    ->numeric()->required()->prefix(Currency::symbol()),
                DatePicker::make('date')->label(__('Date'))->required(),
                TextInput::make('description')->label(__('Description'))->required(),
            ])
            ->fillForm(fn ($record) => [
                'amount' => $record->amount,
                'description' => $record->description,
            ])
            ->action(function (array $data, $record, $component) {
                $record->update([
                    'amount' => $data['amount'],
                    'description' => $data['description'],
                ]);
                $record->refresh();
                static::refreshRecord($component);
            });
    }

    private static function deleteFeeAction(): Action
    {
        return Action::make('deleteFee')
            ->label(__('Delete'))
            ->iconButton()
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->visible(fn ($record) => auth()->user()->can('DeleteFee:Matter'))
            ->requiresConfirmation()
            ->action(function ($record, $component) {
                $record->delete();
                static::refreshRecord($component);
            });
    }

    /**
     * The matter on screen — for a payment's or attachment's buttons, asked
     * for every row: each row loaded its matter again otherwise.
     */
    private static function pageMatter(mixed $record, mixed $livewire): ?Matter
    {
        $matter = method_exists($livewire, 'getRecord') ? $livewire->getRecord() : null;

        return $matter instanceof Matter && (int) $matter->getKey() === (int) $record?->matter_id ? $matter : $record?->matter;
    }

    private static function editAllocationAction(): Action
    {
        return Action::make('editAllocation')
            ->label(__('Edit'))
            ->iconButton()
            ->icon('heroicon-o-pencil')
            ->visible(fn ($record, $livewire) => auth()->user()->can('updateAllocation', static::pageMatter($record, $livewire)))
            ->modalHeading(__('Edit Payment'))
            ->schema([
                TextInput::make('amount')->label(__('Amount'))
                    ->numeric()->required()->prefix(Currency::symbol()),
                DatePicker::make('date')->label(__('Payment Date'))->required(),
                Textarea::make('description')->label(__('Notes / Reference'))->rows(2),
            ])
            ->fillForm(fn ($record) => [
                'amount' => $record->amount,
                'date' => $record->date,
                'description' => $record->description,
            ])
            ->action(function (array $data, $record, $component) {
                $record->update([
                    'amount' => $data['amount'],
                    'date' => $data['date'],
                    'description' => $data['description'],
                ]);
                $record->refresh();
                static::refreshRecord($component);
            });
    }

    private static function deleteAllocationAction(): Action
    {
        return Action::make('deleteAllocation')
            ->label(__('Delete'))
            ->iconButton()
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->visible(fn ($record, $livewire) => auth()->user()->can('deleteAllocation', static::pageMatter($record, $livewire)))
            ->requiresConfirmation()
            ->action(function ($record, $component) {
                $record->delete();
                static::refreshRecord($component);
            });
    }

    private static function addAttachmentsAction(): Action
    {
        return Action::make('addAttachments')
            ->label(__('Add Attachments'))
            ->icon('heroicon-o-plus')
            ->visible(fn ($record) => auth()->user()->can('createAttachment', $record))
            ->modalHeading(__('Add New Attachments'))
            ->schema([
                Repeater::make('attachments_data')
                    ->label(__('Files'))
                    ->schema([
                        FileUpload::make('file')
                            ->label(__('Attachment'))
                            ->disk('public')
                            ->directory('matter-attachments')
                            ->visibility('public')
                            ->required()
                            ->getUploadedFileNameForStorageUsing(fn ($file) => FileUploadHelper::getUniqueFilename($file, 'matter-attachments'))
                            ->live()
                            ->afterStateUpdated(function ($state, $set) {
                                if ($state instanceof TemporaryUploadedFile) {
                                    $set('name', $state->getClientOriginalName());
                                }
                            }),
                        Hidden::make('name'),
                        Select::make('type')
                            ->label(__('Type'))
                            ->options([
                                'initial_report' => __('Initial Report'),
                                'final_report' => __('Final Report'),
                                'supporting_document' => __('Supporting Document'),
                                'correspondence' => __('Correspondence'),
                                'minutes_signed' => __('Minutes signed'),
                                'other' => __('Other'),
                            ])
                            ->required(),
                    ])
                    ->columns(2)
                    ->addActionLabel(__('Add Another File')),
            ])
            ->action(function (array $data, $record, $component) {
                $disk = Storage::disk('public');
                if ($data['attachments_data']) {
                    foreach ($data['attachments_data'] as $item) {
                        $path = $item['file'];
                        $record->attachments()->create([
                            'user_id' => auth()->id(),
                            'type' => $item['type'],
                            'path' => $path,
                            'name' => $item['name'] ?? basename($path),
                            'size' => $disk->exists($path) ? $disk->size($path) : 0,
                            'extension' => pathinfo($path, PATHINFO_EXTENSION) ?? '',
                        ]);
                    }
                }

                $record->refresh();
                $record->unsetRelation('attachments');
                static::refreshComponent($component);
            })
            ->successNotificationTitle(__('Attachments added successfully.'));
    }

    private static function downloadAttachmentAction(): Action
    {
        return Action::make('download')
            ->icon('heroicon-o-arrow-down-tray')
            ->iconButton()
            ->tooltip(__('Download'))
            ->url(fn ($record) => route('attachment.download', $record))
            ->openUrlInNewTab(false);
    }

    private static function deleteAttachmentAction(): Action
    {
        return Action::make('deleteAttachment')
            ->label(__('Delete'))
            ->iconButton()
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->visible(fn ($record, $livewire) => auth()->user()->can('deleteAttachment', static::pageMatter($record, $livewire)))
            ->requiresConfirmation()
            ->action(function ($record, $component) {
                Storage::disk('public')->delete($record->path);
                $record->delete();
                $component->getLivewire()->getRecord()->refresh();
                $component->getLivewire()->getRecord()->unsetRelation('attachments');
                static::refreshComponent($component);
            });
    }
}
