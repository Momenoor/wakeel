<?php

namespace App\Filament\Pms\Resources\Quotations\Schemas;

use App\Enums\PMS\ContractType;
use App\Enums\PMS\InstallmentPaymentMethod;
use App\Models\Lease;
use App\Models\Party;
use App\Models\Unit;
use App\Services\PMS\QuotationService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

/**
 * Input only — the priced figures (base rent, VAT, total) are never entered
 * here. `QuotationService::generate()` computes them from each line's
 * offered rent and the unit's own `vatRate()`, so there is nowhere on this
 * form for those numbers to drift out of sync with what the units actually
 * carry. The expected instalments are previewed live from the same input.
 */
class QuotationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Quotation'))
                    ->schema([
                        Select::make('party_id')
                            ->label(__('Prospect / Tenant'))
                            ->options(fn (): array => Party::withRole('tenant')->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->required(),
                        DatePicker::make('validity_date')
                            ->label(__('Valid Until'))
                            ->default(now()->addDays(14))
                            ->required(),
                    ])->columns(2),

                Section::make(__('Units'))
                    ->schema([
                        Repeater::make('units')
                            ->label(__('Units Offered'))
                            ->schema([
                                Select::make('unit_id')
                                    ->label(__('Unit'))
                                    ->options(fn (): array => Unit::query()
                                        ->with('property')
                                        ->get()
                                        ->mapWithKeys(fn (Unit $unit): array => [
                                            $unit->id => "{$unit->property?->name} — {$unit->unit_number}",
                                        ])->all())
                                    ->searchable()
                                    ->required()
                                    ->distinct()
                                    ->live()
                                    ->afterStateUpdated(fn (Get $get, Set $set) => self::syncSchedule($get, $set, '../../')),
                                TextInput::make('offered_rent')
                                    ->label(__('Offered Rent (AED/year)'))
                                    ->numeric()
                                    ->minValue(0)
                                    ->step(0.01)
                                    ->required()
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(fn (Get $get, Set $set) => self::syncSchedule($get, $set, '../../')),
                            ])
                            ->columns(2)
                            ->minItems(1)
                            ->addActionLabel(__('Add Unit'))
                            ->live()
                            ->afterStateUpdated(function (Get $get, Set $set): void {
                                self::syncContractType($get, $set);
                                self::syncSchedule($get, $set);
                            })
                            ->columnSpanFull(),
                    ]),

                Section::make(__('Contract Details'))
                    ->schema([
                        DatePicker::make('start_date')
                            ->label(__('Start Date'))
                            ->default(now()->addMonth()->startOfMonth())
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                                $set('end_date', $state ? Lease::fullYearEnd($state)->toDateString() : null);
                                self::syncSchedule($get, $set, keepDates: false);
                            }),
                        DatePicker::make('end_date')
                            ->label(__('End Date'))
                            ->default(Lease::fullYearEnd(now()->addMonth()->startOfMonth()))
                            ->required()
                            ->afterOrEqual('start_date')
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Get $get, Set $set) => self::syncSchedule($get, $set, keepDates: false)),
                        TextInput::make('grace_period_days')
                            ->label(__('Grace Period (Days)'))
                            ->numeric()
                            ->minValue(0)
                            ->default(0),
                        Select::make('contract_type')
                            ->label(__('Contract Type'))
                            ->options(fn (Get $get): array => self::contractTypeOptions($get('units'))),
                        TextInput::make('security_deposit')
                            ->label(__('Security Deposit (AED)'))
                            ->numeric()
                            ->minValue(0)
                            ->step(0.01)
                            ->default(0)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Get $get, Set $set) => self::syncSchedule($get, $set)),
                        TextInput::make('number_of_installments')
                            ->label(__('Number of Instalments'))
                            ->helperText(__('How many cheques/payments the rent is split into. VAT and the security deposit are separate instalments.'))
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(48)
                            ->default(1)
                            ->required()
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Get $get, Set $set) => self::syncSchedule($get, $set, keepDates: false)),
                        Select::make('payment_method')
                            ->label(__('Payment Method'))
                            ->options(InstallmentPaymentMethod::class)
                            ->default(InstallmentPaymentMethod::POST_DATED_CHEQUE->value),
                    ])->columns(3),

                Section::make(__('Expected Instalments'))
                    ->description(__('What the lease would be paid in — change any due date as agreed. The instalments are only created once it becomes a lease.'))
                    ->schema([
                        Repeater::make('schedule')
                            ->hiddenLabel()
                            ->schema([
                                Hidden::make('kind'),
                                TextInput::make('label')
                                    ->hiddenLabel()
                                    ->disabled()
                                    ->dehydrated(false),
                                DatePicker::make('due_date')
                                    ->hiddenLabel()
                                    ->required(),
                                TextInput::make('amount')
                                    ->hiddenLabel()
                                    ->disabled()
                                    ->dehydrated(false),
                            ])
                            ->table([
                                TableColumn::make(__('Instalment')),
                                TableColumn::make(__('Due Date')),
                                TableColumn::make(__('Amount (AED)')),
                            ])
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->helperText(__('Add a unit with its rent to see the instalments.')),
                    ]),
            ]);
    }

    /**
     * The expected instalments rebuilt from the rest of the form. Dates
     * already set by hand are kept when only amounts change; a new start,
     * end or number of instalments works them out again.
     */
    private static function syncSchedule(Get $get, Set $set, string $path = '', bool $keepDates = true): void
    {
        $service = app(QuotationService::class);
        $quotation = $service->preview([
            'units' => $get($path.'units'),
            'security_deposit' => $get($path.'security_deposit'),
            'number_of_installments' => $get($path.'number_of_installments'),
            'start_date' => $get($path.'start_date'),
            'end_date' => $get($path.'end_date'),
        ]);

        $rows = (float) $quotation->getAttribute('base_rent') > 0 ? $service->expectedInstallments($quotation) : [];
        $current = array_values($get($path.'schedule') ?? []);

        $set($path.'schedule', self::scheduleState($rows, $keepDates ? $current : []));
    }

    /**
     * Repeater rows for the expected instalments, keeping a date already
     * set on the same row.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $current
     * @return array<string, array<string, mixed>>
     */
    public static function scheduleState(array $rows, array $current = []): array
    {
        $state = [];
        foreach ($rows as $i => $row) {
            $kept = ($current[$i]['kind'] ?? null) === $row['kind'] ? ($current[$i]['due_date'] ?? null) : null;

            $state[(string) Str::uuid()] = [
                'kind' => $row['kind'],
                'label' => $row['number'].'. '.$row['label'],
                'due_date' => filled($kept) ? $kept : $row['due_date']->toDateString(),
                'amount' => number_format($row['amount'], 2),
            ];
        }

        return $state;
    }

    /**
     * @return array<string, string>
     */
    private static function contractTypeOptions(mixed $unitRows): array
    {
        return collect(Lease::allowedContractTypes(self::unitIds($unitRows)))
            ->mapWithKeys(fn (ContractType $type): array => [$type->value => $type->getLabel()])
            ->all();
    }

    private static function syncContractType(Get $get, Set $set): void
    {
        $current = $get('contract_type');
        $current = $current instanceof ContractType ? $current->value : $current;

        if (array_key_exists((string) $current, self::contractTypeOptions($get('units')))) {
            return;
        }

        $set('contract_type', Lease::suggestContractType(self::unitIds($get('units')))?->value);
    }

    /**
     * @return list<int>
     */
    private static function unitIds(mixed $unitRows): array
    {
        return collect(is_array($unitRows) ? $unitRows : [])->pluck('unit_id')->filter()->map(fn ($id): int => (int) $id)->values()->all();
    }
}
