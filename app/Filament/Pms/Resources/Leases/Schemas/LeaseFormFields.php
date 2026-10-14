<?php

namespace App\Filament\Pms\Resources\Leases\Schemas;

use App\Enums\PMS\ContractType;
use App\Enums\PMS\LeasePartyRole;
use App\Enums\PMS\YesNo;
use App\Filament\Pms\Resources\Tenants\Pages\CreateTenant;
use App\Filament\Pms\Resources\Tenants\Schemas\TenantForm;
use App\Models\Lease;
use App\Models\Party;
use App\Support\Currency;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

/**
 * The lease fields the plain form (LeaseForm, also the edit page) and the
 * creation wizard (LeaseWizardForm) both show — one definition of each, so a
 * change to a field reaches both.
 */
class LeaseFormFields
{
    public static function datesSection(): Section
    {
        return Section::make(__('Dates'))
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
            ])->columns(3);
    }

    /**
     * @param  bool  $liveDeposit  the deposit feeds other fields (the wizard's instalments)
     */
    public static function financialsSection(bool $liveDeposit = false): Section
    {
        return Section::make(__('Financials'))
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
                    ->live(onBlur: true, condition: $liveDeposit),
            ])->columns(3);
    }

    public static function occupancySection(): Section
    {
        return Section::make(__('Occupancy & Use'))
            ->schema([
                TextInput::make('number_of_occupants')
                    ->label(__('No. of Occupants'))
                    ->helperText(__('Residential contracts only.'))
                    ->numeric()
                    ->minValue(0),
                Checkbox::make('allow_multiple_licenses')
                    ->label(__('Allow Multiple Licenses'))
                    ->helperText(__('Commercial/industrial contracts only.')),
            ])->columns(3);
    }

    /**
     * @param  bool  $prefixed  labels start with "POA" (the wizard, where the fields sit among others)
     */
    public static function powerOfAttorneySection(bool $prefixed = false): Section
    {
        return Section::make(__('Power of Attorney'))
            ->description(__('Only needed when a delegated representative signs on behalf of a party.'))
            ->schema([
                TextInput::make('poa_authority_number')
                    ->label($prefixed ? __('POA Authority No.') : __('Authority No.'))
                    ->maxLength(255),
                TextInput::make('poa_identification_number')
                    ->label($prefixed ? __('POA EID No. / Trade License No.') : __('EID No. / Trade License No.'))
                    ->maxLength(255),
                TextInput::make('poa_unified_number')
                    ->label($prefixed ? __('POA Unified No.') : __('Unified No.'))
                    ->maxLength(255),
                TextInput::make('poa_name')
                    ->label($prefixed ? __('POA Name') : __('Name'))
                    ->maxLength(255),
            ])->columns(2);
    }

    /**
     * @param  bool  $creatable  a new tenant can be made from the select without leaving the page
     */
    public static function tenantsRepeater(bool $creatable = false): Repeater
    {
        $party = Select::make('party_id')
            ->label(__('Party'))
            ->options(fn (): array => Party::withRole('tenant')->orderBy('name')->pluck('name', 'id')->all())
            ->searchable()
            ->required()
            ->distinct();

        if ($creatable) {
            // A new tenant — party and profile — without leaving the wizard.
            $party
                ->createOptionForm(fn (Schema $schema): Schema => $schema->components(TenantForm::fields())->columns(2))
                ->createOptionModalHeading(__('New tenant'))
                ->createOptionUsing(fn (array $data): int => (int) CreateTenant::createTenant($data)->party_id);
        }

        return Repeater::make('tenants')
            ->label(__('Tenants'))
            ->schema([
                $party,
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
            ->columnSpanFull();
    }

    /**
     * @param  list<int>  $unitIds
     * @return array<string, string>
     */
    public static function contractTypeOptions(array $unitIds): array
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
    public static function syncContractType(Set $set, mixed $current, array $unitIds): void
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
    public static function syncAnnualRent(Set $set, mixed $start, mixed $end, mixed $baseRent): void
    {
        $annual = Lease::annualRentFor($start, $end, $baseRent);

        $set('annual_rent', $annual === null ? null : number_format($annual, 2, '.', ''));
    }
}
