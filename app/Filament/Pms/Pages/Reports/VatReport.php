<?php

namespace App\Filament\Pms\Pages\Reports;

use App\Filament\Pms\Support\PortfolioScope;
use App\Models\Installment;
use App\Support\ReportDateRangeFilter;
use BackedEnum;
use Filament\Tables\Columns\Summarizers\Count;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Output VAT on rent for the VAT return: every instalment carrying VAT in
 * the period (by date of supply, or due date when none is recorded), with
 * its tax invoice number, both TRNs, net, rate, VAT and total. Filter by
 * owner group to get one landlord's figures.
 */
class VatReport extends PmsReport
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calculator';

    protected static ?int $navigationSort = 8;

    public static function reportTitle(): string
    {
        return __('VAT report');
    }

    public function table(Table $table): Table
    {
        $supplyDate = DB::raw('COALESCE(installments.date_of_supply, installments.due_date)');

        return $this->report($table)
            ->query(fn () => Installment::query()
                ->where('vat_amount', '>', 0)
                ->with(['lease.leaseParties.party', 'lease.units.property'])
                ->select('installments.*')
                ->selectRaw('COALESCE(installments.date_of_supply, installments.due_date) as supply_date'))
            ->defaultSort(fn (Builder $query) => $query->orderBy($supplyDate))
            ->columns([
                TextColumn::make('tax_invoice_serial')
                    ->label(__('Tax invoice'))
                    ->placeholder('—')
                    ->searchable()
                    ->summarize(Count::make()->label(__('Invoices'))),
                TextColumn::make('supply_date')
                    ->label(__('Date of supply'))
                    ->date('d/m/Y'),
                TextColumn::make('tenant')
                    ->label(__('Tenant'))
                    ->state(fn (Installment $installment) => static::tenantOf($installment->lease))
                    ->description(fn (Installment $installment) => $installment->tenant_trn ? __('TRN').' '.$installment->tenant_trn : null)
                    ->wrap(),
                TextColumn::make('units_label')
                    ->label(__('Units'))
                    ->state(fn (Installment $installment) => static::unitsOf($installment->lease))
                    ->wrap(),
                TextColumn::make('landlord_trn')
                    ->label(__('Landlord TRN'))
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('net_amount')
                    ->label(__('Net'))
                    ->aed()
                    ->alignEnd()
                    ->summarize(Sum::make()->label('')->aed()),
                TextColumn::make('vat_rate')
                    ->label(__('Rate'))
                    // Stored as a fraction (0.05), shown like the lease prints.
                    ->formatStateUsing(fn ($state) => number_format((float) $state * 100, 2).'%')
                    ->alignCenter(),
                TextColumn::make('vat_amount')
                    ->label(__('VAT'))
                    ->aed()
                    ->alignEnd()
                    ->weight('bold')
                    ->summarize(Sum::make()->label('')->aed()),
                TextColumn::make('total_due_amount')
                    ->label(__('Total'))
                    ->aed()
                    ->alignEnd()
                    ->summarize(Sum::make()->label('')->aed()),
            ])
            ->filters([
                ...$this->portfolioFilters(fn (Builder $query, ?int $group, ?int $property) => PortfolioScope::installments($query, $group, $property)),
                ReportDateRangeFilter::make(
                    column: 'supply_date',
                    label: __('Date of supply'),
                    applyUsing: fn (Builder $query, $from, $until) => $query
                        ->when($from, fn (Builder $q) => $q->whereRaw('DATE(COALESCE(installments.date_of_supply, installments.due_date)) >= ?', [$from->toDateString()]))
                        ->when($until, fn (Builder $q) => $q->whereRaw('DATE(COALESCE(installments.date_of_supply, installments.due_date)) <= ?', [$until->toDateString()])),
                    name: 'supply_between',
                    defaultPreset: 'last_quarter',
                )->columnSpan(2),
            ]);
    }
}
