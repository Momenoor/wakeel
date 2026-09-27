<?php

namespace App\Filament\Pms\Pages\Reports;

use App\Filament\Pms\Support\PortfolioScope;
use App\Models\Lease;
use App\Support\Sql;
use BackedEnum;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Who owes rent that's already past due, and for how long: per lease, the
 * unpaid balance of its overdue instalments split into 0–30, 31–60, 61–90
 * and 90+ days, plus penalties and the oldest unpaid due date.
 */
class ArrearsAgingReport extends PmsReport
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?int $navigationSort = 4;

    public static function reportTitle(): string
    {
        return __('Arrears aging');
    }

    public function table(Table $table): Table
    {
        $age = Sql::daysSince('due_date');
        $bucket = fn (string $condition) => "COALESCE(SUM(CASE WHEN {$condition} THEN balance_due ELSE 0 END), 0)";

        $overdue = DB::table('installments')
            ->where('balance_due', '>', 0.005)
            ->whereDate('due_date', '<', now()->toDateString())
            ->where('is_security_deposit', false)
            ->groupBy('lease_id')
            ->select('lease_id')
            ->selectRaw($bucket("{$age} <= 30").' as bucket_30')
            ->selectRaw($bucket("{$age} BETWEEN 31 AND 60").' as bucket_60')
            ->selectRaw($bucket("{$age} BETWEEN 61 AND 90").' as bucket_90')
            ->selectRaw($bucket("{$age} > 90").' as bucket_over')
            ->selectRaw('COALESCE(SUM(balance_due), 0) as total_overdue')
            ->selectRaw('COALESCE(SUM(admin_penalty_amount), 0) as penalties')
            ->selectRaw('MIN(due_date) as oldest_due')
            ->selectRaw('COUNT(*) as overdue_count');

        return $this->report($table)
            ->query(fn () => Lease::query()
                ->joinSub($overdue, 'arrears', 'arrears.lease_id', '=', 'leases.id')
                ->with(['leaseParties.party', 'units.property'])
                ->select('leases.*', 'arrears.*')
                ->selectRaw(Sql::daysSince('arrears.oldest_due').' as oldest_days'))
            ->defaultSort('total_overdue', 'desc')
            ->emptyStateHeading(__('No arrears'))
            ->emptyStateIcon('heroicon-o-check-circle')
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
                TextColumn::make('oldest_due')
                    ->label(__('Oldest due'))
                    ->date('d/m/Y')
                    ->description(fn (Lease $lease) => __(':days days', ['days' => (int) $lease->oldest_days]))
                    ->sortable(),
                TextColumn::make('bucket_30')->label(__('0–30 days'))->money('AED')->alignEnd()->sortable()
                    ->summarize(Sum::make()->label('')->money('AED')),
                TextColumn::make('bucket_60')->label(__('31–60 days'))->money('AED')->alignEnd()->sortable()
                    ->summarize(Sum::make()->label('')->money('AED')),
                TextColumn::make('bucket_90')->label(__('61–90 days'))->money('AED')->alignEnd()->sortable()
                    ->summarize(Sum::make()->label('')->money('AED')),
                TextColumn::make('bucket_over')->label(__('90+ days'))->money('AED')->alignEnd()->sortable()
                    ->color(fn ($state) => (float) $state > 0 ? 'danger' : null)
                    ->summarize(Sum::make()->label('')->money('AED')),
                TextColumn::make('total_overdue')
                    ->label(__('Total overdue'))
                    ->money('AED')
                    ->alignEnd()
                    ->weight('bold')
                    ->color('danger')
                    ->sortable()
                    ->summarize(Sum::make()->label('')->money('AED')),
                TextColumn::make('penalties')
                    ->label(__('Penalties'))
                    ->money('AED')
                    ->alignEnd()
                    ->sortable()
                    ->summarize(Sum::make()->label('')->money('AED')),
            ])
            ->filters([
                ...$this->portfolioFilters(fn (Builder $query, ?int $group, ?int $property) => PortfolioScope::leases($query, $group, $property)),
                SelectFilter::make('age')
                    ->label(__('Oldest overdue'))
                    ->options([
                        '30' => __('More than 30 days'),
                        '60' => __('More than 60 days'),
                        '90' => __('More than 90 days'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $q, $days) => $q->whereRaw(Sql::daysSince('arrears.oldest_due').' > ?', [(int) $days]),
                    )),
            ]);
    }
}
