<?php

namespace App\Filament\Mms\Resources\Matters\Schemas;

use App\Enums\FeeType;
use App\Enums\MatterDifficulty;
use App\Enums\MatterLevel;
use App\Filament\Mms\Resources\Parties\Schemas\PartyForm;
use App\Filament\Support\CustomFieldInput;
use App\Filament\Support\FeeVatFields;
use App\Models\Fee;
use App\Models\Party;
use App\Models\Type;
use App\Services\MMS\IncentiveReversals;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;

class MatterForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(3)
                    ->schema([
                        Group::make()
                            ->schema([
                                Section::make(__('Basic Data'))->schema([
                                    TextInput::make('year')
                                        ->label(__('Year'))
                                        ->minValue(now()->subYears(5)->year)
                                        ->maxValue(now()->year)
                                        ->length(4)
                                        ->numeric()
                                        ->rules(['digits:4'])
                                        ->required(),
                                    TextInput::make('number')
                                        ->label(__('Number'))
                                        ->required(),

                                    DatePicker::make('received_at')
                                        ->label(__('Court Assigning Date'))
                                        ->required(),
                                    DatePicker::make('distributed_at')
                                        ->label(__('Assistant Assigning Date'))
                                        ->afterOrEqual('received_at')
                                        ->required(),
                                    DateTimePicker::make('next_session_date')
                                        ->required()
                                        ->format('Y-m-d H:i')
                                        ->seconds(false)
                                        ->afterOrEqual('received_at')
                                        ->label(__('Next Session Date'))
                                        ->live(),
                                    Toggle::make('is_office_work')
                                        ->label(__('Is Office Work'))
                                        ->default(false),
                                    DatePicker::make('initial_report_at')
                                        ->label(__('Initial Report Date'))
                                        ->visible(fn (string $operation, $record) => $operation === 'edit'
                                            && $record->initial_report_at !== null
                                            && auth()->user()->can('UpdateInitialReportDate:Matter')
                                        ),
                                    DatePicker::make('final_report_at')
                                        ->label(__('Final Report Date'))
                                        ->visible(fn (string $operation, $record) => $operation === 'edit'
                                            && $record->final_report_at !== null
                                            && auth()->user()->can('UpdateFinalReportDate:Matter')
                                        ),
                                    DatePicker::make('final_report_memo_date')
                                        ->label(__('Final Report Memo Date'))
                                        ->visible(fn (string $operation, $record) => $operation === 'edit'
                                            && $record->final_report_memo_date !== null
                                            && auth()->user()->can('UpdateFinalReportDate:Matter')
                                        ),
                                    TextInput::make('review_count')
                                        ->label(__('Review Count'))
                                        ->numeric()
                                        ->visible(fn (string $operation, $record) => $operation === 'edit'
                                            && $record->review_count !== null
                                            && auth()->user()->can('Update:Matter')
                                        )
                                        ->default(0),
                                    Toggle::make('has_substantive_changes')
                                        ->label(__('Has Substantive Changes'))
                                        ->visible(fn (string $operation, $record) => $operation === 'edit'
                                            && $record->review_count !== null
                                            && auth()->user()->can('Update:Matter')
                                        ),
                                    Toggle::make('has_court_penalty')
                                        ->label(__('Has Court Penalty'))
                                        ->visible(fn (string $operation, $record) => $operation === 'edit'
                                            && $record->review_count !== null
                                            && auth()->user()->can('Update:Matter')
                                        ),
                                ]),
                                Section::make(__('Court Data'))->schema([
                                    Select::make('court_id')
                                        ->label(__('Court'))
                                        ->relationship('court', 'name')
                                        ->searchable()
                                        ->preload()
                                        ->required()
                                        ->columnSpanFull(),
                                    Select::make('level')
                                        ->label(__('Level'))
                                        ->options(MatterLevel::class)
                                        ->required(),
                                    Select::make('type_id')
                                        ->label(__('Type'))
                                        ->relationship('type', 'name', modifyQueryUsing: function ($query) {
                                            $query->where('active', true);
                                        })
                                        ->searchable()
                                        ->preload()
                                        ->required()
                                        ->live(),
                                    Group::make()
                                        ->schema(function (Get $get, Set $set) {
                                            $typeId = $get('type_id');
                                            if (! $typeId) {
                                                return [];
                                            }

                                            $type = Type::remembered($typeId);
                                            if (! $type) {
                                                return [];
                                            }

                                            return CustomFieldInput::components(
                                                $type->fieldDefinitions,
                                                (array) ($get('custom_fields') ?? []),
                                                fn (array $slots) => $set('custom_fields', $slots),
                                            );
                                        })
                                        ->columns(2)
                                        ->columnSpanFull(),
                                    Select::make('difficulty')
                                        ->label(__('Difficulty'))
                                        ->options(MatterDifficulty::class)
                                        ->required(),
                                    // live() is needed here so expert type options
                                    // re-evaluate when commissioning changes
                                    Toggle::make('is_committee')
                                        ->label(__('Is Committee?'))
                                        ->live()
                                        ->afterStateHydrated(function ($state, Set $set, Get $get) {
                                            $set('is_committee', $get('commissioning') === 'committee');
                                        })
                                        ->afterStateUpdated(function ($state, Set $set) {
                                            $set('commissioning', $state ? 'committee' : 'individual');
                                        })
                                        ->inline(false),
                                    Select::make('commissioning')
                                        ->label(__('Commissioning'))
                                        ->options([
                                            'individual' => __('Individual'),
                                            'committee' => __('Committee'),
                                        ])
                                        ->required()
                                        ->live()
                                        ->afterStateHydrated(function ($state, Set $set) {
                                            $set('is_committee', $state === 'committee');
                                        })
                                        ->afterStateUpdated(function ($state, Set $set) {
                                            $set('is_committee', $state === 'committee');
                                        }),
                                ]),
                            ])
                            ->columns(1)
                            ->columnSpan(1),

                        Group::make()
                            ->schema([
                                Section::make(__('Parties & Experts'))
                                    ->schema([
                                        Repeater::make('expertsOnly')
                                            ->relationship('expertsOnly')
                                            ->label(__('Experts'))
                                            ->columns(3)
                                            ->table([
                                                Repeater\TableColumn::make(__('Type'))->width(250),
                                                Repeater\TableColumn::make(__('Name')),
                                                Repeater\TableColumn::make(__('Commission %')),
                                            ])
                                            ->defaultItems(1)
                                            ->default([['type' => 'certified', 'party_id' => 7358]])
                                            ->compact()
                                            ->schema([
                                                Select::make('type')
                                                    ->label(__('Type'))
                                                    ->options(function (Get $get, $livewire) {
                                                        // Layout components (Grid/Group/Section) are transparent
                                                        // to Filament's data tree — commissioning lives flat on $livewire->data
                                                        $commissioning = $livewire->data['commissioning'] ?? 'individual';
                                                        $isCommittee = $commissioning === 'committee';
                                                        $options = [
                                                            'certified' => __('Certified Expert'),
                                                            'assistant' => __('Assistant Expert'),
                                                        ];
                                                        if ($isCommittee) {
                                                            $options['external'] = __('External Expert');
                                                            $options['external-assistant'] = __('External Assistant');
                                                        }

                                                        return $options;
                                                    })
                                                    ->required()
                                                    ->live(onBlur: true) // onBlur — only re-render when leaving the field
                                                    ->afterStateUpdated(function (Set $set) {
                                                        $set('role', 'expert');
                                                        $set('party_id', null);
                                                    })
                                                    ->columnSpan(1),

                                                // No ->live() — nothing downstream reads party_id changes
                                                Select::make('party_id')
                                                    ->label(__('Expert Name'))
                                                    ->relationship('party', 'name', function ($query, Get $get) {
                                                        $type = $get('type');
                                                        if ($type) {
                                                            $query->whereJsonContains('role', [['role' => 'expert', 'type' => $type]]);
                                                        } else {
                                                            $query->whereJsonContains('role', [['role' => 'expert']]);
                                                        }
                                                    })
                                                    ->createOptionForm(fn (Schema $schema) => PartyForm::configure($schema))
                                                    ->createOptionAction(function (Action $action, Get $get) {
                                                        $type = $get('type') ?? null;

                                                        return $action->fillForm([
                                                            'role' => [
                                                                'role' => ['expert'],
                                                                'type' => [$type],
                                                            ],
                                                        ]);
                                                    })
                                                    ->createOptionUsing(function (array $data) {
                                                        return Party::create($data)->id;
                                                    })
                                                    ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                                                    ->columnSpan(2)
                                                    ->searchable()
                                                    ->preload()
                                                    ->required(),

                                                TextInput::make('commission_percentage')
                                                    ->label(__('Commission %'))
                                                    ->numeric()
                                                    ->minValue(0)
                                                    ->maxValue(100)
                                                    ->suffix('%')
                                                    ->visible(fn (Get $get) => in_array($get('type'), ['assistant', 'external-assistant'])),

                                                Hidden::make('matter_id'),
                                                Hidden::make('role')->default('expert'),
                                                Hidden::make('id'),
                                            ])
                                            ->addActionLabel(__('Add Expert'))
                                            ->itemLabel(function (array $state): ?string {
                                                $type = $state['type'] ?? '';
                                                $role = $state['role'] ?? '';

                                                return $type ? "{$type} ({$role})" : null;
                                            })
                                            ->addAction(
                                                fn (Action $action) => $action
                                                    ->color('warning')
                                                    ->icon('heroicon-m-plus-circle')
                                            )
                                            ->addActionAlignment(Alignment::Start),

                                        Repeater::make('mainPartiesOnly')
                                            ->relationship('mainPartiesOnly')
                                            ->label(__('Parties'))
                                            ->columns(3)
                                            ->itemNumbers()
                                            ->schema([
                                                // Named as the matter's type calls each side (المتنازع, الطاعن …).
                                                Select::make('type')
                                                    ->label(__('Type'))
                                                    ->options(fn (Get $get) => Type::sideOptions(Type::remembered($get('../../type_id'))))
                                                    ->required()
                                                    ->live(onBlur: true) // onBlur reduces round-trips
                                                    ->afterStateUpdated(function (Set $set) {
                                                        $set('role', 'party');
                                                        $set('party_id', null);
                                                        $set('representatives', []);
                                                    }),

                                                // Live: the party's latest phone and email show under it.
                                                Select::make('party_id')
                                                    ->label(__('Party Name'))
                                                    ->relationship('party', 'name', function ($query) {
                                                        $query->whereJsonContains('role', [['role' => 'party']]);
                                                    })
                                                    ->createOptionForm(fn (Schema $schema) => PartyForm::configure($schema))
                                                    ->editOptionForm(fn (Schema $schema) => PartyForm::configure($schema))
                                                    ->createOptionAction(function (Action $action) {
                                                        return $action->fillForm([
                                                            'role' => [
                                                                'role' => ['party'],
                                                            ],
                                                        ]);
                                                    })
                                                    ->createOptionUsing(function (array $data) {
                                                        return Party::create($data)->id;
                                                    })
                                                    ->searchable()
                                                    // Not preloaded: found by typing. 50 names fetched for every row of
                                                    // the form (thousands of parties) made each open of a matter slow.
                                                    ->live()
                                                    ->helperText(fn (Get $get): ?string => self::contactLine($get('party_id')))
                                                    ->columnSpan(2)
                                                    ->required(),

                                                Hidden::make('matter_id'),
                                                Hidden::make('role')->default('party'),
                                                Hidden::make('id'),

                                                Repeater::make('representatives')
                                                    ->relationship('representatives')
                                                    ->label(__('Representatives / Lawyers'))
                                                    ->table([
                                                        Repeater\TableColumn::make(__('Name'))->width('60%'),
                                                        Repeater\TableColumn::make(__('Email'))->width('25%'),
                                                        Repeater\TableColumn::make(__('Phone'))->width('15%'),
                                                    ])
                                                    ->columns(2)
                                                    // ->compact()
                                                    ->schema([
                                                        Select::make('party_id')
                                                            ->label(__('Representative'))
                                                            ->relationship('party', 'name', function ($query) {
                                                                $query->whereJsonContains('role', [['role' => 'representative']]);
                                                            })
                                                            ->createOptionForm(fn (Schema $schema) => PartyForm::configure($schema))
                                                            ->editOptionForm(fn (Schema $schema) => PartyForm::configure($schema))
                                                            ->createOptionAction(function (Action $action) {
                                                                return $action->fillForm([
                                                                    'role' => [
                                                                        'role' => ['representative'],
                                                                    ],
                                                                ]);
                                                            })
                                                            ->createOptionUsing(function (array $data) {
                                                                return Party::create($data)->id;
                                                            })
                                                            ->afterStateUpdated(function (Get $get, Set $set) {
                                                                $party = Party::find($get('party_id'));
                                                                $set('party_email', $party?->latestEmail());
                                                                $set('party_phone', $party?->latestPhone());
                                                            })
                                                            ->live(onBlur: true)
                                                            // Not preloaded: found by typing. 50 names fetched for every row of
                                                            // the form (thousands of parties) made each open of a matter slow.
                                                            ->required()
                                                            ->searchable()
                                                            ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                                                            ->columnSpanFull(),
                                                        // The representative's latest email and phone — shown, not saved
                                                        // here (they're kept on the party).
                                                        TextInput::make('party_email')
                                                            ->disabled()
                                                            ->dehydrated(false)
                                                            ->afterStateHydrated(fn (TextInput $component, Get $get) => $component->state(Party::find($get('party_id'))?->latestEmail()))
                                                            ->extraInputAttributes(['dir' => 'ltr']),
                                                        TextInput::make('party_phone')
                                                            ->disabled()
                                                            ->dehydrated(false)
                                                            ->afterStateHydrated(fn (TextInput $component, Get $get) => $component->state(Party::find($get('party_id'))?->latestPhone()))
                                                            ->extraInputAttributes(['dir' => 'ltr']),
                                                        Hidden::make('role')->default('representative'),

                                                        Hidden::make('type')
                                                            ->dehydrateStateUsing(fn (Get $get) => $get('../../type')),

                                                        Hidden::make('parent_id')
                                                            ->dehydrateStateUsing(fn (Get $get) => $get('../../id')),

                                                        Hidden::make('matter_id')
                                                            ->dehydrateStateUsing(fn (Get $get, $state) => $state ?: $get('../../matter_id')),
                                                    ])
                                                    ->addAction(
                                                        fn (Action $action) => $action
                                                            ->color('warning')
                                                            ->icon('heroicon-m-plus-circle')
                                                    )
                                                    ->addActionLabel(__('Add Representative'))
                                                    ->compact()
                                                    ->columnSpanFull(),
                                            ])
                                            ->addActionLabel(__('Add Party'))
                                            ->itemLabel(function (array $state): ?string {
                                                $type = $state['type'] ?? '';
                                                $role = $state['role'] ?? '';

                                                return $type ? __($role).' ('.__($type).')' : null;
                                            })
                                            ->addActionAlignment(Alignment::Start)
                                            ->addAction(
                                                fn (Action $action) => $action
                                                    ->color('warning')
                                                    ->icon('heroicon-m-plus-circle')
                                            ),
                                    ]),

                                Section::make(__('Fees'))
                                    ->visible(fn () => auth()->user()->can('CreateFee:Matter') || auth()->user()->can('UpdateFee:Matter'))
                                    ->schema([
                                        Repeater::make('fees')
                                            ->label(__('Fees'))
                                            ->relationship('fees')
                                            ->table([
                                                Repeater\TableColumn::make(__('Type')),
                                                Repeater\TableColumn::make(__('Amount')),
                                                Repeater\TableColumn::make(__('Including VAT'))
                                                    ->alignment(Alignment::Center),
                                                Repeater\TableColumn::make(__('Basic · VAT · Total')),
                                            ])
                                            ->compact()
                                            // Each fee's VAT on the fee itself (FeeVatFields): shown as entered, kept split.
                                            ->mutateRelationshipDataBeforeFillUsing(fn (array $data): array => [
                                                ...$data,
                                                'amount' => ($data['is_vat_included'] ?? false) ? round((float) $data['amount'] + (float) ($data['vat'] ?? 0), 2) : $data['amount'],
                                            ])
                                            ->mutateRelationshipDataBeforeCreateUsing(fn (array $data): array => [...$data, ...FeeVatFields::attributes($data)])
                                            ->mutateRelationshipDataBeforeSaveUsing(fn (array $data, Fee $record): array => [...$data, ...FeeVatFields::attributes($data, $record)])
                                            ->schema([
                                                Select::make('type')
                                                    ->label(__('Type'))
                                                    // A VAT row of before keeps its type; a new fee carries its own VAT.
                                                    ->options(fn ($state): array => FeeType::isVat($state) ? [FeeType::VAT->value => FeeType::VAT->getLabel(), ...FeeType::newFeeOptions()] : FeeType::newFeeOptions())
                                                    ->disabled(fn ($state): bool => FeeType::isVat($state))
                                                    ->dehydrated()
                                                    ->afterStateUpdated(FeeVatFields::typeChosen())
                                                    ->live()
                                                    ->required(),

                                                TextInput::make('amount')
                                                    ->label(__('Amount'))
                                                    ->numeric()
                                                    ->required()
                                                    ->prefix(fn (Get $get) => FeeType::of($get('type'))?->isNegative() ? '-' : '+')
                                                    ->live(debounce: 400),

                                                FeeVatFields::toggle()
                                                    ->extraAttributes(['class' => 'mx-4']),

                                                FeeVatFields::preview(),
                                            ])
                                            ->columns(1)
                                            ->addActionLabel(__('Add Fee'))
                                            // A fee whose incentive was already paid says so before it goes (IncentiveReversals).
                                            ->deleteAction(fn (Action $action) => $action
                                                ->requiresConfirmation()
                                                ->modalDescription(fn (array $arguments): string => IncentiveReversals::deleteWarning(
                                                    str_starts_with((string) ($arguments['item'] ?? ''), 'record-') ? Fee::find((int) substr($arguments['item'], 7)) : null
                                                ))),
                                    ]),
                            ])
                            ->columnSpan(2),
                    ])->columnSpanFull(),
            ]);
    }

    /**
     * "📞 0501234567 · ✉ name@example.com" — a party's latest phone and email,
     * under its name on the form.
     */
    private static function contactLine(mixed $partyId): ?string
    {
        if (blank($partyId)) {
            return null;
        }

        // Asked for on every redraw of every party row: read once a request.
        $key = 'party_contact_'.$partyId;
        $request = request();

        if (! $request->attributes->has($key)) {
            $request->attributes->set($key, Party::query()->find($partyId, ['id', 'phone', 'email']));
        }

        $party = $request->attributes->get($key);
        $parts = array_filter(['📞 '.($party?->latestPhone() ?? ''), '✉ '.($party?->latestEmail() ?? '')], fn (string $part): bool => mb_strlen($part) > 2);

        return $parts !== [] ? implode(' · ', $parts) : __('No phone or email yet');
    }
}
