<?php

namespace App\Filament\Pms\Resources\Leases\Schemas;

use App\Enums\PMS\Emirate;
use App\Enums\PMS\InstallmentPaymentMethod;
use App\Enums\PMS\PropertyClassification;
use App\Models\ConditionTemplate;
use App\Models\Unit;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

/**
 * Raw-input lease creation — the other path, converting an accepted
 * `Quotation`, goes through `LeaseService::createFromQuotation()` from
 * the quotation's own view page instead of this form. Also reused,
 * unchanged, by `EditLease` — only reachable while the lease is `DRAFT`.
 */
class LeaseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                LeaseFormFields::datesSection(),

                LeaseFormFields::financialsSection(),

                Section::make(__('Contract Identification'))
                    ->description(__('Fields required by the property\'s emirate for its own tenancy contract format (Ejari, Sharjawi, …).'))
                    ->schema([
                        TextInput::make('government_contract_number')
                            ->label(__('Contract No.'))
                            ->maxLength(255),
                        Select::make('contract_type')
                            ->label(__('Contract Type'))
                            ->options(fn (Get $get): array => LeaseFormFields::contractTypeOptions((array) $get('units')))
                            ->helperText(__('Only the types that fit the selected units\' classification are offered.')),
                        Select::make('payment_method')
                            ->label(__('Payment Method'))
                            ->options(InstallmentPaymentMethod::class),
                        TextInput::make('number_of_payments')
                            ->label(__('No. of Payments'))
                            ->numeric()
                            ->minValue(0),
                        Select::make('condition_template_id')
                            ->label(__('Conditions Template'))
                            ->options(fn (): array => ConditionTemplate::orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->helperText(__('Auto-suggested from the selected units\' property once chosen below — change it if a different template applies.')),
                    ])->columns(3),

                LeaseFormFields::occupancySection(),

                LeaseFormFields::powerOfAttorneySection(),

                Section::make(__('Tenants'))
                    ->description(__('At least one Primary Tenant is required; co-tenants and guarantors are optional.'))
                    ->schema([
                        LeaseFormFields::tenantsRepeater(),
                    ]),

                Section::make(__('Units'))
                    ->schema([
                        Select::make('units')
                            ->label(__('Units'))
                            ->options(fn (): array => Unit::query()
                                ->with('property')
                                ->get()
                                ->mapWithKeys(fn (Unit $unit): array => [
                                    $unit->id => "{$unit->property?->name} — {$unit->unit_number}",
                                ])->all())
                            ->multiple()
                            ->searchable()
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Set $set, Get $get, ?array $state): void {
                                $set('condition_template_id', self::suggestConditionTemplateId($state ?? []));
                                LeaseFormFields::syncContractType($set, $get('contract_type'), $state ?? []);
                            })
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * Suggests the conditions template matching the first selected unit's
     * property (emirate + commercial-vs-residential classification) — the
     * office can still override the Select afterward.
     *
     * @param  list<int>  $unitIds
     */
    private static function suggestConditionTemplateId(array $unitIds): ?int
    {
        $unit = Unit::query()->with('property')->find($unitIds[0] ?? null);
        $property = $unit?->property;

        if ($unit === null || $property === null || $property->emirate === null) {
            return null;
        }

        $format = match (true) {
            $property->emirate === Emirate::DUBAI => 'dubai_ejari',
            in_array($unit->property_classification, [PropertyClassification::COMMERCIAL, PropertyClassification::INDUSTRIAL], true) => 'sharjah_commercial',
            default => 'sharjah_residential',
        };

        return ConditionTemplate::where('contract_format', $format)->value('id');
    }
}
