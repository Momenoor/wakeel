<?php

namespace App\Filament\Pms\Resources\Leases\Schemas;

use App\Enums\PMS\ContractType;
use App\Enums\PMS\Emirate;
use App\Enums\PMS\InstallmentPaymentMethod;
use App\Enums\PMS\LeasePartyRole;
use App\Enums\PMS\PropertyClassification;
use App\Enums\PMS\YesNo;
use App\Filament\Pms\Resources\Properties\RelationManagers\UnitsRelationManager;
use App\Filament\Pms\Resources\Tenants\Pages\CreateTenant;
use App\Filament\Pms\Resources\Tenants\Schemas\TenantForm;
use App\Models\ConditionTemplate;
use App\Models\Lease;
use App\Models\Party;
use App\Models\Property;
use App\Models\Quotation;
use App\Models\Unit;
use App\Services\PMS\InstallmentGenerator;
use App\Services\PMS\QuotationService;
use App\Support\ChequeNumber;
use App\Support\Currency;
use App\Support\UaeBanks;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The guided, step-by-step lease creation flow — used only by `CreateLease`
 * (via Filament's `HasWizard` page concern). Editing an existing lease
 * keeps the flat `LeaseForm` (a correction, not a fresh walkthrough). A
 * lease made here always covers exactly one property.
 */
class LeaseWizardForm
{
    /**
     * @return list<Step>
     */
    public static function steps(): array
    {
        return [
            self::propertyStep(),
            self::unitsStep(),
            self::tenantsStep(),
            self::contractDetailsStep(),
            self::installmentsStep(),
            self::conditionsStep(),
        ];
    }

    private static function propertyStep(): Step
    {
        return Step::make(__('Property'))
            ->schema([
                ToggleButtons::make('property_id')
                    ->label(__('Select the Property'))
                    ->options(fn (): array => Property::orderBy('name')->pluck('name', 'id')->all())
                    ->icons(fn (): array => Property::orderBy('name')->pluck('id')
                        ->mapWithKeys(fn (int $id): array => [$id => Heroicon::OutlinedBuildingOffice2])
                        ->all())
                    ->required()
                    ->live()
                    ->columns(5)
                    ->extraAttributes(['class' => 'pms-property-picker'])
                    ->afterStateUpdated(function (Set $set, Get $get, ?int $state): void {
                        $firstUnitId = $state !== null
                            ? Unit::query()->where('property_id', $state)->orderBy('id')->value('id')
                            : null;

                        $set('units', $firstUnitId !== null ? [['unit_id' => $firstUnitId]] : []);
                        $set('condition_template_id', self::suggestConditionTemplateId(
                            $state,
                            $firstUnitId !== null ? [$firstUnitId] : [],
                        ));
                        $set('contract_type', Lease::suggestContractType($firstUnitId !== null ? [$firstUnitId] : [])?->value);

                        if ($state !== null && blank($get('government_contract_number'))) {
                            $set('government_contract_number', self::suggestContractNumber($state));
                        }
                    }),
            ]);
    }

    /**
     * Its own step, scoped to whichever property was picked on the step
     * before — defaults to that property's first unit already selected
     * rather than an empty required row, since a lease can't mean anything
     * yet without knowing what it covers.
     */
    private static function unitsStep(): Step
    {
        return Step::make(__('Units'))
            ->schema([
                Repeater::make('units')
                    ->label(__('Units'))
                    ->schema([
                        Select::make('unit_id')
                            ->label(__('Unit'))
                            ->options(function (Get $get): array {
                                $propertyId = $get('../../property_id');

                                if (blank($propertyId)) {
                                    return [];
                                }

                                return Unit::query()
                                    ->where('property_id', $propertyId)
                                    ->pluck('unit_number', 'id')
                                    ->all();
                            })
                            ->searchable()
                            ->required()
                            ->distinct()
                            ->live()
                            // A unit not in the system yet, added to the
                            // selected property without leaving the wizard.
                            ->createOptionForm(fn (Schema $schema): Schema => $schema->components(UnitsRelationManager::fields())->columns(2))
                            ->createOptionModalHeading(__('New unit'))
                            ->createOptionAction(fn (Action $action, Get $get): Action => $action->visible(filled($get('../../property_id'))))
                            ->createOptionUsing(fn (array $data, Get $get): int => Unit::create([
                                ...$data,
                                'property_id' => $get('../../property_id'),
                            ])->getKey()),
                    ])
                    ->table([
                        TableColumn::make(__('Unit')),
                    ])
                    ->minItems(1)
                    ->defaultItems(1)
                    ->addActionLabel(__('Add Unit'))
                    ->live()
                    ->afterStateUpdated(function (Set $set, Get $get, ?array $state): void {
                        $set('condition_template_id', self::suggestConditionTemplateId(
                            $get('property_id'),
                            self::selectedUnitIds($state),
                        ));
                        self::syncContractType($set, $get('contract_type'), self::selectedUnitIds($state));
                    })
                    ->columnSpanFull(),
            ]);
    }

    private static function tenantsStep(): Step
    {
        return Step::make(__('Tenants'))
            ->schema([
                Repeater::make('tenants')
                    ->label(__('Tenants'))
                    ->schema([
                        Select::make('party_id')
                            ->label(__('Party'))
                            ->options(fn (): array => Party::withRole('tenant')->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->required()
                            ->distinct()
                            // A new tenant — party and profile — without
                            // leaving the wizard.
                            ->createOptionForm(fn (Schema $schema): Schema => $schema->components(TenantForm::fields())->columns(2))
                            ->createOptionModalHeading(__('New tenant'))
                            ->createOptionUsing(fn (array $data): int => (int) CreateTenant::createTenant($data)->party_id),
                        Select::make('role')
                            ->label(__('Role'))
                            ->options(LeasePartyRole::class)
                            ->default(LeasePartyRole::PRIMARY_TENANT->value)
                            ->required(),
                    ])
                    ->table([
                        TableColumn::make(__('Party')),
                        TableColumn::make(__('Role')),
                    ])
                    ->minItems(1)
                    ->defaultItems(1)
                    ->addActionLabel(__('Add Tenant'))
                    ->columnSpanFull(),
            ]);
    }

    private static function contractDetailsStep(): Step
    {
        return Step::make(__('Contract Details'))
            ->schema([
                Section::make(__('Dates'))
                    ->schema([
                        DatePicker::make('start_date')
                            ->label(__('Start Date'))
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Set $set, Get $get, ?string $state): void {
                                $end = $state ? Lease::fullYearEnd($state)->toDateString() : null;

                                $set('end_date', $end);
                                self::syncAnnualRent($set, $state, $end, $get('total_base_rent'));
                            }),
                        DatePicker::make('end_date')
                            ->label(__('End Date'))
                            ->required()
                            ->afterOrEqual('start_date')
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Set $set, Get $get, ?string $state) => self::syncAnnualRent($set, $get('start_date'), $state, $get('total_base_rent')))
                            ->helperText(__('Defaults to a full year — the day before the start date\'s anniversary. Adjust if the contract runs differently.')),
                        DatePicker::make('issue_date')
                            ->label(__('Issue Date'))
                            ->default(now()),
                        TextInput::make('grace_period_days')
                            ->label(__('Grace Period (Days)'))
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->required(),
                    ])->columns(3),

                Section::make(__('Financials'))
                    ->schema([
                        TextInput::make('total_base_rent')->suffix(Currency::symbol())
                            ->label(Currency::label(__('Total Base Rent (AED)')))
                            ->numeric()
                            ->minValue(0)
                            ->step(0.01)
                            ->required()
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Set $set, Get $get, $state) => self::syncAnnualRent($set, $get('start_date'), $get('end_date'), $state)),
                        TextInput::make('annual_rent')
                            ->label(Currency::label(__('Annual Rent (AED)')))
                            ->disabled()
                            ->dehydrated(false)
                            ->helperText(__('Calculated from the contract period and the base rent — a full year equals the base rent.')),
                        Select::make('multiple_rent_amount')
                            ->label(__('Multiple Rent Amount'))
                            ->options(YesNo::class)
                            ->default(YesNo::NO->value)
                            ->required(),
                        TextInput::make('security_deposit_amount')->suffix(Currency::symbol())
                            ->label(Currency::label(__('Security Deposit (AED)')))
                            ->numeric()
                            ->minValue(0)
                            ->step(0.01)
                            ->default(0)
                            ->live(onBlur: true),
                    ])->columns(3),

                Section::make(__('Contract Identification'))
                    ->schema([
                        TextInput::make('government_contract_number')
                            ->label(__('Contract No.'))
                            ->helperText(__('Auto-suggested from the property — change it if needed.'))
                            ->maxLength(255),
                        Select::make('contract_type')
                            ->label(__('Contract Type'))
                            ->options(fn (Get $get): array => self::contractTypeOptions(self::selectedUnitIds($get('units'))))
                            ->helperText(__('Only the types that fit the selected units\' classification are offered.')),
                    ])->columns(2),

                Section::make(__('Occupancy & Use'))
                    ->schema([
                        TextInput::make('number_of_occupants')
                            ->label(__('No. of Occupants'))
                            ->helperText(__('Residential contracts only.'))
                            ->numeric()
                            ->minValue(0),
                        Checkbox::make('allow_multiple_licenses')
                            ->label(__('Allow Multiple Licenses'))
                            ->helperText(__('Commercial/industrial contracts only.')),
                    ])->columns(3),

                Section::make(__('Power of Attorney'))
                    ->description(__('Only needed when a delegated representative signs on behalf of a party.'))
                    ->schema([
                        TextInput::make('poa_authority_number')
                            ->label(__('POA Authority No.'))
                            ->maxLength(255),
                        TextInput::make('poa_identification_number')
                            ->label(__('POA EID No. / Trade License No.'))
                            ->maxLength(255),
                        TextInput::make('poa_unified_number')
                            ->label(__('POA Unified No.'))
                            ->maxLength(255),
                        TextInput::make('poa_name')
                            ->label(__('POA Name'))
                            ->maxLength(255),
                    ])->columns(2),
            ]);
    }

    /**
     * The rent instalments — generated from their number, each still
     * editable — then the VAT and the security deposit, each always its own
     * instalment with an amount worked out from the contract: VAT is never
     * folded into a rent cheque.
     */
    private static function installmentsStep(): Step
    {
        return Step::make(__('Installments & Payments'))
            ->schema([
                Section::make(__('Rent instalments'))
                    ->columns(3)
                    ->schema([
                        TextInput::make('number_of_installments')
                            ->label(__('Number of Instalments'))
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(48)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Get $get, Set $set) => self::generateRentRows($get, $set))
                            ->helperText(__('Fills the rows below: the rent split evenly, due every few months from the start date. Every row can still be changed.')),
                        Select::make('installment_method')
                            ->label(__('Payment Method'))
                            ->options(InstallmentPaymentMethod::class)
                            ->default(InstallmentPaymentMethod::POST_DATED_CHEQUE->value)
                            ->live()
                            ->afterStateUpdated(fn (Get $get, Set $set) => self::generateRentRows($get, $set)),
                        Placeholder::make('installments_target')
                            ->label(__('Rent to schedule'))
                            ->content(fn (Get $get) => Currency::label(__(':amount AED', ['amount' => number_format((float) ($get('total_base_rent') ?? 0), 2)]))),
                        // For all rows at once — each row can still be changed.
                        UaeBanks::select('installment_bank')
                            ->label(__('Bank for all instalments'))
                            ->live()
                            ->afterStateUpdated(fn (Get $get, Set $set) => self::applyBank($get, $set)),
                        TextInput::make('first_reference')
                            ->label(__('First cheque / reference number'))
                            ->helperText(__('The next rows get the following numbers: 000101, 000102, …'))
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Get $get, Set $set) => self::applyReferences($get, $set)),

                        Repeater::make('installments')
                            ->label(__('Installments'))
                            ->schema(self::paymentFields(withAmount: true))
                            ->table([
                                TableColumn::make(__('Payment Method')),
                                TableColumn::make(__('Payment Date')),
                                TableColumn::make(__('Amount')),
                                TableColumn::make(__('Reference')),
                                TableColumn::make(__('Bank Name')),
                            ])
                            ->minItems(1)
                            ->addActionLabel(__('Add Installment'))
                            ->columnSpanFull()
                            ->rule(function (Get $get): Closure {
                                return function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                    $target = round((float) ($get('total_base_rent') ?? 0), 2);
                                    $sum = round(collect($value)->sum(fn (array $row): float => (float) ($row['amount'] ?? 0)), 2);

                                    if (abs($sum - $target) > 0.01) {
                                        $fail(__('The rent instalments must add up to the rent, :target AED — currently :sum AED. VAT and the security deposit are separate instalments.', [
                                            'target' => number_format($target, 2),
                                            'sum' => number_format($sum, 2),
                                        ]));
                                    }
                                };
                            }),
                    ]),

                Section::make(__('VAT instalment'))
                    ->description(fn (Get $get) => Currency::label(__('The whole contract\'s VAT, :amount AED, paid as its own instalment.', [
                        'amount' => number_format(self::vatAmount($get), 2),
                    ])))
                    ->visible(fn (Get $get): bool => self::vatAmount($get) > 0)
                    ->schema([
                        Group::make(self::paymentFields(withAmount: false))
                            ->statePath('vat_payment')
                            ->columns(4),
                    ]),

                Section::make(__('Security deposit instalment'))
                    ->description(fn (Get $get) => Currency::label(__('The security deposit, :amount AED, paid as its own instalment.', [
                        'amount' => number_format((float) ($get('security_deposit_amount') ?? 0), 2),
                    ])))
                    ->visible(fn (Get $get): bool => (float) ($get('security_deposit_amount') ?? 0) > 0)
                    ->schema([
                        Group::make(self::paymentFields(withAmount: false))
                            ->statePath('deposit_payment')
                            ->columns(4),
                    ]),
            ]);
    }

    /**
     * One payment's fields: method, date, (amount,) cheque/transfer number
     * and the bank — used for the rent rows, the VAT and the deposit.
     *
     * @return list<mixed>
     */
    private static function paymentFields(bool $withAmount): array
    {
        $needsReference = [
            InstallmentPaymentMethod::POST_DATED_CHEQUE->value,
            InstallmentPaymentMethod::BANK_TRANSFER->value,
            InstallmentPaymentMethod::DIRECT_DEBIT_UAEDD->value,
        ];

        return array_values(array_filter([
            Select::make('payment_method')
                ->label(__('Payment Method'))
                ->options(InstallmentPaymentMethod::class)
                ->required()
                ->live(),
            DatePicker::make('payment_date')
                ->label(__('Payment Date'))
                ->required()
                ->live(onBlur: true)
                ->helperText(fn (Get $get): ?string => self::installmentDateWarning($get, $get('payment_date'))),
            $withAmount
                ? TextInput::make('amount')->suffix(Currency::symbol())
                    ->label(Currency::label(__('Amount (AED)')))
                    ->numeric()
                    ->minValue(0.01)
                    ->step(0.01)
                    ->required()
                    ->live(onBlur: true)
                : null,
            TextInput::make('reference_number')
                ->label(fn (Get $get): string => match (self::method($get('payment_method'))) {
                    InstallmentPaymentMethod::POST_DATED_CHEQUE->value => __('Check Number'),
                    InstallmentPaymentMethod::BANK_TRANSFER->value, InstallmentPaymentMethod::DIRECT_DEBIT_UAEDD->value => __('Transfer Number'),
                    default => __('Reference Number'),
                })
                // Recorded for every instalment — required for a cheque or a
                // transfer, a receipt number otherwise. A cheque number is
                // six digits: 36 becomes 000036.
                ->required(fn (Get $get): bool => in_array(self::method($get('payment_method')), $needsReference, true))
                ->live(onBlur: true)
                ->afterStateUpdated(function (?string $state, Get $get, Set $set) {
                    $formatted = ChequeNumber::forMethod($state, $get('payment_method'));
                    if ($formatted !== $state) {
                        $set('reference_number', $formatted);
                    }
                })
                ->dehydrateStateUsing(fn (?string $state, Get $get) => ChequeNumber::forMethod($state, $get('payment_method')))
                ->maxLength(255),
            UaeBanks::select()
                ->visible(fn (Get $get): bool => in_array(self::method($get('payment_method')), $needsReference, true))
                ->required(fn (Get $get): bool => self::method($get('payment_method')) === InstallmentPaymentMethod::POST_DATED_CHEQUE->value),
        ]));
    }

    /**
     * The "bank for all" choice onto every rent row and the VAT and
     * deposit instalments.
     */
    private static function applyBank(Get $get, Set $set): void
    {
        $bank = $get('installment_bank');

        if (blank($bank)) {
            return;
        }

        foreach (array_keys($get('installments') ?? []) as $key) {
            $set("installments.{$key}.bank_name", $bank);
        }

        $set('vat_payment.bank_name', $bank);
        $set('deposit_payment.bank_name', $bank);
    }

    /**
     * Consecutive cheque/reference numbers from the first one: the rent
     * rows in order, then the VAT and the deposit cheques.
     */
    private static function applyReferences(Get $get, Set $set): void
    {
        $first = trim((string) $get('first_reference'));

        if ($first === '') {
            return;
        }

        // Cheques: six digits, so 36, 37 … become 000036, 000037 ….
        $first = (string) ChequeNumber::forMethod($first, $get('installment_method'));
        $set('first_reference', $first);

        $i = 0;
        foreach (array_keys($get('installments') ?? []) as $key) {
            $set("installments.{$key}.reference_number", self::nthReference($first, $i++));
        }

        $set('vat_payment.reference_number', self::nthReference($first, $i++));
        $set('deposit_payment.reference_number', self::nthReference($first, $i));
    }

    /**
     * "000101" + 2 = "000103", leading zeros kept; "CHQ-45" + 1 = "CHQ-46".
     * A reference without a number at the end is only used for the first.
     */
    public static function nthReference(string $first, int $offset): ?string
    {
        if ($offset === 0) {
            return $first;
        }

        if (! preg_match('/^(.*?)(\d+)$/', $first, $m)) {
            return null;
        }

        return $m[1].str_pad((string) ((int) $m[2] + $offset), strlen($m[2]), '0', STR_PAD_LEFT);
    }

    /**
     * A payment method as its value: the Select holds the enum itself once
     * set, so comparing it with a string would always fail.
     */
    private static function method(mixed $method): ?string
    {
        return $method instanceof InstallmentPaymentMethod ? $method->value : (filled($method) ? (string) $method : null);
    }

    /**
     * The rent rows from the chosen number of instalments: the rent split
     * evenly, due every few whole months from the start date (21/06, 21/08,
     * 21/10 … for 6 over a year). The VAT and deposit instalments default to
     * the start date and the same payment method.
     */
    private static function generateRentRows(Get $get, Set $set): void
    {
        $count = (int) $get('number_of_installments');
        $start = $get('start_date');
        $end = $get('end_date');

        if ($count < 1 || $count > 48 || blank($start) || blank($end)) {
            return;
        }

        $method = self::method($get('installment_method')) ?? InstallmentPaymentMethod::POST_DATED_CHEQUE->value;

        $set('installments', self::rentRows(
            $count,
            $method,
            (float) ($get('total_base_rent') ?? 0),
            $start,
            $end,
            $get('installment_bank') ?: null,
            trim((string) $get('first_reference')),
        ));
        self::applyBank($get, $set);
        self::applyReferences($get, $set);

        foreach (['vat_payment', 'deposit_payment'] as $separate) {
            if (blank($get($separate.'.payment_date'))) {
                $set($separate.'.payment_date', Carbon::parse($start)->toDateString());
            }
            if (blank($get($separate.'.payment_method'))) {
                $set($separate.'.payment_method', $method);
            }
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function rentRows(int $count, string $method, float $totalRent, mixed $start, mixed $end, ?string $bank = null, string $firstReference = ''): array
    {
        $amounts = InstallmentGenerator::splitEvenly($totalRent, $count);
        $dates = InstallmentGenerator::dueDates($start, $end, $count);

        $rows = [];
        foreach ($amounts as $i => $amount) {
            $rows[(string) Str::uuid()] = [
                'payment_method' => $method,
                'payment_date' => $dates[$i]->toDateString(),
                'amount' => number_format($amount, 2, '.', ''),
                'reference_number' => $firstReference !== '' ? self::nthReference($firstReference, $i) : null,
                'bank_name' => $bank,
            ];
        }

        return $rows;
    }

    /**
     * The wizard filled in from a quotation: its property, units, tenant,
     * period, rent, deposit and the rent instalments it showed — left for
     * the office to check and add the cheque numbers and banks. A lease
     * covers one property, so only the quotation's units in the first
     * unit's property are taken.
     *
     * @return array<string, mixed>
     */
    public static function fromQuotation(Quotation $quotation): array
    {
        $quotation->loadMissing('units');
        [$start, $end] = app(QuotationService::class)->period($quotation);

        // The due dates the quotation showed, including any set by hand.
        $expected = collect(app(QuotationService::class)->expectedInstallments($quotation));
        $rentDates = $expected->where('kind', 'rent')->pluck('due_date')->values();
        $dateOf = fn (string $kind): string => ($expected->firstWhere('kind', $kind)['due_date'] ?? $start)->toDateString();

        $propertyId = $quotation->units->first()?->property_id;
        $propertyId = $propertyId === null ? null : (int) $propertyId;
        $units = $quotation->units->filter(fn (Unit $unit): bool => (int) $unit->property_id === $propertyId)->values();
        $unitIds = $units->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $rent = round((float) $units->sum(fn (Unit $unit): float => (float) $unit->pivot->offered_rent), 2);

        $method = $quotation->payment_method?->value ?? InstallmentPaymentMethod::POST_DATED_CHEQUE->value;
        $count = max(1, (int) $quotation->number_of_installments);
        $contractType = $quotation->contract_type?->value;
        if (! array_key_exists((string) $contractType, self::contractTypeOptions($unitIds))) {
            $contractType = Lease::suggestContractType($unitIds)?->value;
        }

        $installments = self::rentRows($count, $method, $rent, $start, $end);
        foreach (array_keys($installments) as $i => $key) {
            if ($rentDates->has($i)) {
                $installments[$key]['payment_date'] = $rentDates->get($i)->toDateString();
            }
        }

        return [
            'property_id' => $propertyId,
            'units' => array_map(fn (int $id): array => ['unit_id' => $id], $unitIds),
            'tenants' => [['party_id' => $quotation->party_id, 'role' => LeasePartyRole::PRIMARY_TENANT->value]],
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'issue_date' => now()->toDateString(),
            'grace_period_days' => (int) $quotation->grace_period_days,
            'total_base_rent' => number_format($rent, 2, '.', ''),
            'annual_rent' => ($annual = Lease::annualRentFor($start, $end, $rent)) === null ? null : number_format($annual, 2, '.', ''),
            'multiple_rent_amount' => YesNo::NO->value,
            'security_deposit_amount' => number_format((float) $quotation->security_deposit, 2, '.', ''),
            'contract_type' => $contractType,
            'government_contract_number' => $propertyId ? self::suggestContractNumber($propertyId) : null,
            'condition_template_id' => self::suggestConditionTemplateId($propertyId, $unitIds),
            'number_of_installments' => $count,
            'installment_method' => $method,
            'installments' => $installments,
            'vat_payment' => ['payment_method' => $method, 'payment_date' => $dateOf('vat')],
            'deposit_payment' => ['payment_method' => $method, 'payment_date' => $dateOf('deposit')],
        ];
    }

    private static function vatAmount(Get $get): float
    {
        return round((float) ($get('total_base_rent') ?? 0) * self::selectedUnitsVatRate($get), 2);
    }

    private static function conditionsStep(): Step
    {
        return Step::make(__('Special Conditions'))
            ->schema([
                Select::make('condition_template_id')
                    ->label(__('Conditions Template'))
                    ->options(fn (): array => ConditionTemplate::orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->helperText(__('Auto-suggested from the selected property and units — change it if a different template applies.')),
            ]);
    }

    /**
     * @param  list<int>  $unitIds
     * @return array<string, string>
     */
    private static function contractTypeOptions(array $unitIds): array
    {
        return collect(Lease::allowedContractTypes($unitIds))
            ->mapWithKeys(fn (ContractType $type): array => [$type->value => $type->getLabel()])
            ->all();
    }

    /**
     * Keeps the chosen contract type valid as the units change: a type that
     * no longer fits is replaced by the units' own suggestion, or cleared.
     *
     * @param  list<int>  $unitIds
     */
    private static function syncContractType(Set $set, mixed $current, array $unitIds): void
    {
        if (array_key_exists((string) $current, self::contractTypeOptions($unitIds))) {
            return;
        }

        $set('contract_type', Lease::suggestContractType($unitIds)?->value);
    }

    /**
     * The read-only Annual Rent field: blank until the dates and base
     * rent that determine it are all filled in.
     */
    private static function syncAnnualRent(Set $set, mixed $start, mixed $end, mixed $baseRent): void
    {
        $annual = Lease::annualRentFor($start, $end, $baseRent);

        $set('annual_rent', $annual === null ? null : number_format($annual, 2, '.', ''));
    }

    /**
     * @param  list<int>|null  $unitIds
     */
    private static function suggestConditionTemplateId(?int $propertyId, ?array $unitIds): ?int
    {
        $property = $propertyId ? Property::find($propertyId) : null;
        $unit = ! empty($unitIds) ? Unit::find($unitIds[0]) : null;

        if ($property === null || $property->emirate === null) {
            return null;
        }

        $format = match (true) {
            $property->emirate === Emirate::DUBAI => 'dubai_ejari',
            $unit !== null && in_array($unit->property_classification, [PropertyClassification::COMMERCIAL, PropertyClassification::INDUSTRIAL], true) => 'sharjah_commercial',
            default => 'sharjah_residential',
        };

        return ConditionTemplate::where('contract_format', $format)->value('id');
    }

    /**
     * A serial unique to the property: its own `property_number`, followed
     * by how many leases have already covered a unit in it — still just a
     * suggestion, the office can type over it.
     */
    private static function suggestContractNumber(int $propertyId): ?string
    {
        $property = Property::find($propertyId);

        if ($property === null || blank($property->property_number)) {
            return null;
        }

        $sequence = Lease::whereHas('units', fn ($query) => $query->where('property_id', $propertyId))->count() + 1;

        return sprintf('%s-%04d', $property->property_number, $sequence);
    }

    /**
     * A non-blocking heads-up when a row's payment date falls outside the
     * lease's own start/end — legitimate in some cases (a deposit paid
     * ahead of the start date), so it warns rather than refuses to save.
     */
    private static function installmentDateWarning(Get $get, ?string $paymentDate): ?string
    {
        // `$get` is scoped to the current rent row (the lease's dates two
        // levels up) or to the VAT/deposit group (one level up).
        $startDate = $get('../../start_date') ?? $get('../start_date');
        $endDate = $get('../../end_date') ?? $get('../end_date');

        if (blank($paymentDate) || blank($startDate) || blank($endDate)) {
            return null;
        }

        $date = Carbon::parse($paymentDate);

        if ($date->between(Carbon::parse($startDate), Carbon::parse($endDate))) {
            return null;
        }

        return __('This date falls outside the lease period (:start – :end).', [
            'start' => Carbon::parse($startDate)->format('d/m/Y'),
            'end' => Carbon::parse($endDate)->format('d/m/Y'),
        ]);
    }

    /**
     * @param  array<int, array{unit_id?: int|null}>|null  $unitRows
     * @return list<int>
     */
    private static function selectedUnitIds(?array $unitRows): array
    {
        return collect($unitRows ?? [])->pluck('unit_id')->filter()->values()->all();
    }

    /**
     * The rental-rate-weighted VAT rate across the units picked so far —
     * the same formula `Lease::vatRate()` uses, computed here before the
     * lease itself exists yet.
     */
    private static function selectedUnitsVatRate(Get $get): float
    {
        $units = Unit::query()->whereIn('id', self::selectedUnitIds($get('units')))->get();
        $totalRentalRate = (float) $units->sum(fn (Unit $unit): float => (float) $unit->rental_rate);

        if ($totalRentalRate <= 0.0) {
            return $units->isEmpty() ? 0.0 : (float) $units->avg(fn (Unit $unit): float => $unit->vatRate());
        }

        $weighted = $units->sum(fn (Unit $unit): float => (float) $unit->rental_rate * $unit->vatRate());

        return $weighted / $totalRentalRate;
    }
}
