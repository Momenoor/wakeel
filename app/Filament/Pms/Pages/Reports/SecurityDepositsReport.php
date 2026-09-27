<?php

namespace App\Filament\Pms\Pages\Reports;

use App\Enums\PMS\LeaseStatus;
use App\Filament\Pms\Support\PortfolioScope;
use App\Models\Lease;
use BackedEnum;
use Filament\Tables\Columns\Summarizers\Count;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Deposits held against leases: the agreed amount, how much of it was
 * actually collected, and — once a lease has ended — which deposits are
 * now due back to the tenant.
 */
class SecurityDepositsReport extends PmsReport
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    protected static ?int $navigationSort = 9;

    public static function reportTitle(): string
    {
        return __('Security deposits');
    }

    /**
     * Ended — by status, or by its end date passing. A renewed lease's
     * deposit normally carries over to the renewal, so it isn't due back.
     */
    private static function hasEnded(Lease $lease): bool
    {
        if ($lease->status === LeaseStatus::RENEWED) {
            return false;
        }

        return in_array($lease->status, [LeaseStatus::EXPIRED, LeaseStatus::TERMINATED], true)
            || $lease->end_date?->isPast();
    }

    public function table(Table $table): Table
    {
        return $this->report($table)
            ->query(fn () => Lease::query()
                ->where('security_deposit_amount', '>', 0)
                ->whereNotIn('status', [LeaseStatus::QUOTATION->value, LeaseStatus::DRAFT->value])
                ->with(['leaseParties.party', 'units.property'])
                ->withSum(['installments as deposit_collected' => fn (Builder $q) => $q->where('is_security_deposit', true)], 'paid_amount'))
            ->defaultSort('end_date')
            ->columns([
                TextColumn::make('tenant')
                    ->label(__('Tenant'))
                    ->state(fn (Lease $lease) => static::tenantOf($lease))
                    ->weight('bold')
                    ->wrap(),
                TextColumn::make('units_label')
                    ->label(__('Units'))
                    ->state(fn (Lease $lease) => static::unitsOf($lease))
                    ->wrap(),
                TextColumn::make('status')
                    ->label(__('Lease status'))
                    ->badge(),
                TextColumn::make('end_date')
                    ->label(__('Lease end'))
                    ->date('d/m/Y')
                    ->sortable()
                    ->summarize(Count::make()->label(__('Leases'))),
                TextColumn::make('security_deposit_amount')
                    ->label(__('Deposit'))
                    ->money('AED')
                    ->alignEnd()
                    ->sortable()
                    ->summarize(Sum::make()->label('')->money('AED')),
                TextColumn::make('deposit_collected')
                    ->label(__('Collected'))
                    ->money('AED')
                    ->alignEnd()
                    ->placeholder('—')
                    ->color(fn (Lease $lease) => (float) $lease->deposit_collected + 0.005 < (float) $lease->security_deposit_amount ? 'warning' : 'success')
                    ->summarize(Sum::make()->label('')->money('AED')),
                TextColumn::make('refund')
                    ->label(__('Refund'))
                    ->state(fn (Lease $lease) => match (true) {
                        $lease->status === LeaseStatus::RENEWED => __('Carried to renewal'),
                        self::hasEnded($lease) => __('Due'),
                        default => __('Held'),
                    })
                    ->badge()
                    ->color(fn (Lease $lease) => self::hasEnded($lease) ? 'danger' : 'gray'),
            ])
            ->filters([
                ...$this->portfolioFilters(fn (Builder $query, ?int $group, ?int $property) => PortfolioScope::leases($query, $group, $property)),
                Filter::make('refund_due')
                    ->label(__('Refund due only'))
                    ->toggle()
                    ->query(fn (Builder $query) => $query
                        ->where('status', '!=', LeaseStatus::RENEWED->value)
                        ->where(fn (Builder $q) => $q
                            ->whereIn('status', [LeaseStatus::EXPIRED->value, LeaseStatus::TERMINATED->value])
                            ->orWhereDate('end_date', '<', now()->toDateString()))),
                SelectFilter::make('status')
                    ->label(__('Lease status'))
                    ->options(LeaseStatus::class)
                    ->multiple(),
            ]);
    }
}
