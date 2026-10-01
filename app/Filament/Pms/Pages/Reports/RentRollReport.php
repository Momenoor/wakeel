<?php

namespace App\Filament\Pms\Pages\Reports;

use App\Enums\PMS\UnitStatus;
use App\Enums\PMS\UnitType;
use App\Filament\Pms\Support\PortfolioScope;
use App\Models\Lease;
use App\Models\Unit;
use BackedEnum;
use Carbon\Carbon;
use Filament\Tables\Columns\Summarizers\Count;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Every unit with who is in it on what terms: its status, current tenant,
 * lease dates and rent, the deposit held — and, for an empty unit, since
 * when it's been empty.
 */
class RentRollReport extends PmsReport
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-home-modern';

    protected static ?int $navigationSort = 1;

    public static function reportTitle(): string
    {
        return __('Rent roll');
    }

    public function table(Table $table): Table
    {
        // When the unit's last lease ended — how long a vacant unit has
        // been empty.
        $lastLeaseEnd = DB::table('leases')
            ->join('lease_unit', 'lease_unit.lease_id', '=', 'leases.id')
            ->whereColumn('lease_unit.unit_id', 'units.id')
            ->selectRaw('MAX(leases.end_date)');

        return $this->report($table)
            ->query(fn () => Unit::query()
                ->with(['property.ownerGroup', 'activeLeases.leaseParties.party'])
                ->select('units.*')
                ->selectSub($lastLeaseEnd, 'last_lease_end'))
            // By building, then unit number.
            ->defaultSort(fn (Builder $query) => $query
                ->orderBy(DB::table('properties')->select('name')->whereColumn('properties.id', 'units.property_id'))
                ->orderBy('units.unit_number'))
            ->columns([
                TextColumn::make('property.name')
                    ->label(__('Building'))
                    ->description(fn (Unit $unit) => $unit->property?->ownerGroup?->name)
                    ->searchable()
                    ->wrap(),
                TextColumn::make('unit_number')
                    ->label(__('Unit'))
                    ->weight('bold')
                    ->searchable()
                    ->sortable()
                    ->summarize(Count::make()->label(__('Units'))),
                TextColumn::make('unit_type')
                    ->label(__('Type')),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge(),
                TextColumn::make('tenant')
                    ->label(__('Tenant'))
                    ->state(fn (Unit $unit) => static::tenantOf($this->lease($unit)))
                    ->placeholder('—')
                    ->wrap(),
                TextColumn::make('lease_start')
                    ->label(__('Lease start'))
                    ->state(fn (Unit $unit) => $this->lease($unit)?->start_date)
                    ->date('d/m/Y')
                    ->placeholder('—'),
                TextColumn::make('lease_end')
                    ->label(__('Lease end'))
                    ->state(fn (Unit $unit) => $this->lease($unit)?->end_date)
                    ->date('d/m/Y')
                    ->placeholder('—'),
                TextColumn::make('annual_rent')
                    ->label(__('Annual rent'))
                    ->state(fn (Unit $unit) => $this->lease($unit)?->annual_rent)
                    ->aed()
                    ->alignEnd()
                    ->placeholder('—'),
                TextColumn::make('rental_rate')
                    ->label(__('Asking rent'))
                    ->aed()
                    ->alignEnd()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('deposit')
                    ->label(__('Deposit'))
                    ->state(fn (Unit $unit) => $this->lease($unit)?->security_deposit_amount)
                    ->aed()
                    ->alignEnd()
                    ->placeholder('—'),
                TextColumn::make('days_vacant')
                    ->label(__('Vacant for'))
                    ->state(fn (Unit $unit) => $unit->status === UnitStatus::VACANT && $unit->last_lease_end
                        ? max(0, (int) Carbon::parse($unit->last_lease_end)->startOfDay()->diffInDays(now()->startOfDay()))
                        : null)
                    ->formatStateUsing(fn ($state) => __(':days days', ['days' => $state]))
                    ->placeholder('—'),
            ])
            ->filters([
                ...$this->portfolioFilters(fn (Builder $query, ?int $group, ?int $property) => PortfolioScope::units($query, $group, $property)),
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options(UnitStatus::class)
                    ->multiple(),
                SelectFilter::make('unit_type')
                    ->label(__('Type'))
                    ->options(UnitType::class)
                    ->multiple(),
            ]);
    }

    private function lease(Unit $unit): ?Lease
    {
        return $unit->activeLeases->first();
    }
}
