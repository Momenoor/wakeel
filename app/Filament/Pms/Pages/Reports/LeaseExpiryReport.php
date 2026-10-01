<?php

namespace App\Filament\Pms\Pages\Reports;

use App\Enums\PMS\LeaseDisputeStatus;
use App\Enums\PMS\LeaseStatus;
use App\Filament\Pms\Support\PortfolioScope;
use App\Models\Lease;
use BackedEnum;
use Filament\Tables\Columns\Summarizers\Count;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Active leases coming to an end — or already past their end date and
 * still active — with how many days are left, the rent at stake, and
 * whether a renewal has been started or the lease is in dispute.
 */
class LeaseExpiryReport extends PmsReport
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?int $navigationSort = 5;

    public static function reportTitle(): string
    {
        return __('Lease expiry');
    }

    public function table(Table $table): Table
    {
        return $this->report($table)
            ->query(fn () => Lease::query()
                ->where('status', LeaseStatus::ACTIVE->value)
                ->with(['leaseParties.party', 'units.property'])
                ->withCount('renewals'))
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
                TextColumn::make('start_date')
                    ->label(__('Start'))
                    ->date('d/m/Y')
                    ->sortable()
                    ->summarize(Count::make()->label(__('Leases'))),
                TextColumn::make('end_date')
                    ->label(__('End'))
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('days_left')
                    ->label(__('Days left'))
                    ->state(fn (Lease $lease) => (int) now()->startOfDay()->diffInDays($lease->end_date, false))
                    ->badge()
                    ->formatStateUsing(fn (int $state) => $state < 0
                        ? __('Ended :days days ago', ['days' => -$state])
                        : __(':days days', ['days' => $state]))
                    ->color(fn (int $state) => match (true) {
                        $state < 0 => 'danger',
                        $state <= 30 => 'danger',
                        $state <= 60 => 'warning',
                        default => 'info',
                    }),
                TextColumn::make('annual_rent')
                    ->label(__('Annual rent'))
                    ->aed()
                    ->alignEnd()
                    ->sortable()
                    ->summarize(Sum::make()->label('')->aed()),
                TextColumn::make('renewal')
                    ->label(__('Renewal'))
                    ->state(fn (Lease $lease) => $lease->renewals_count > 0 ? __('Started') : __('Not started'))
                    ->badge()
                    ->color(fn (Lease $lease) => $lease->renewals_count > 0 ? 'success' : 'gray'),
                TextColumn::make('dispute_status')
                    ->label(__('Dispute'))
                    ->badge()
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                ...$this->portfolioFilters(fn (Builder $query, ?int $group, ?int $property) => PortfolioScope::leases($query, $group, $property)),
                SelectFilter::make('window')
                    ->label(__('Ending'))
                    ->options([
                        'ended' => __('Already ended, still active'),
                        '30' => __('Within 30 days'),
                        '60' => __('Within 60 days'),
                        '90' => __('Within 90 days'),
                        '180' => __('Within 6 months'),
                    ])
                    ->default('90')
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'ended' => $query->whereDate('end_date', '<', now()->toDateString()),
                        '30', '60', '90', '180' => $query->whereDate('end_date', '<=', now()->addDays((int) $data['value'])->toDateString()),
                        default => $query,
                    }),
                SelectFilter::make('renewal')
                    ->label(__('Renewal'))
                    ->options([
                        'none' => __('Not started'),
                        'started' => __('Started'),
                    ])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'none' => $query->doesntHave('renewals'),
                        'started' => $query->has('renewals'),
                        default => $query,
                    }),
                SelectFilter::make('dispute_status')
                    ->label(__('Dispute'))
                    ->options(LeaseDisputeStatus::class),
            ]);
    }
}
