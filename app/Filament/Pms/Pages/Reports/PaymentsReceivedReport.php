<?php

namespace App\Filament\Pms\Pages\Reports;

use App\Enums\PMS\InstallmentPaymentMethod;
use App\Filament\Pms\Support\PortfolioScope;
use App\Models\InstallmentPayment;
use App\Support\ReportDateRangeFilter;
use BackedEnum;
use Filament\Tables\Columns\Summarizers\Count;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every payment received, for reconciling against the bank: date, tenant,
 * unit, the instalment it paid, method, bank and reference, amount.
 */
class PaymentsReceivedReport extends PmsReport
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?int $navigationSort = 6;

    public static function reportTitle(): string
    {
        return __('Payments received');
    }

    public function table(Table $table): Table
    {
        return $this->report($table)
            ->query(fn () => InstallmentPayment::query()
                ->with(['installment.lease.leaseParties.party', 'installment.lease.units.property']))
            ->defaultSort('paid_date', 'desc')
            ->columns([
                TextColumn::make('paid_date')
                    ->label(__('Paid on'))
                    ->date('d/m/Y')
                    ->sortable()
                    ->summarize(Count::make()->label(__('Payments'))),
                TextColumn::make('tenant')
                    ->label(__('Tenant'))
                    ->state(fn (InstallmentPayment $payment) => static::tenantOf($payment->installment?->lease))
                    ->weight('bold')
                    ->wrap(),
                TextColumn::make('units_label')
                    ->label(__('Units'))
                    ->state(fn (InstallmentPayment $payment) => static::unitsOf($payment->installment?->lease))
                    ->wrap(),
                TextColumn::make('installment.due_date')
                    ->label(__('Instalment due'))
                    ->date('d/m/Y'),
                TextColumn::make('payment_method')
                    ->label(__('Method'))
                    ->badge(),
                TextColumn::make('bank_name')
                    ->label(__('Bank'))
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('transaction_reference')
                    ->label(__('Reference'))
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('amount')
                    ->label(__('Amount'))
                    ->aed()
                    ->alignEnd()
                    ->weight('bold')
                    ->sortable()
                    ->summarize(Sum::make()->label('')->aed()),
            ])
            ->filters([
                ...$this->portfolioFilters(fn (Builder $query, ?int $group, ?int $property) => $query->whereHas(
                    'installment',
                    fn (Builder $installments) => PortfolioScope::installments($installments, $group, $property),
                )),
                ReportDateRangeFilter::make(column: 'paid_date', label: __('Paid on'), name: 'paid_between', defaultPreset: 'this_month')
                    ->columnSpan(2),
                SelectFilter::make('payment_method')
                    ->label(__('Method'))
                    ->options(InstallmentPaymentMethod::class)
                    ->multiple(),
            ]);
    }
}
