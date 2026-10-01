<?php

namespace App\Filament\Pms\Pages\Reports;

use App\Enums\PMS\LeaseStatus;
use App\Enums\PMS\UnitStatus;
use App\Models\Property;
use BackedEnum;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Per building: how many units, how many are let, empty, under
 * maintenance or reserved, the occupancy rate, and the annual rent of the
 * leases in force there. The rent roll lists the vacant units themselves.
 */
class OccupancyReport extends PmsReport
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?int $navigationSort = 2;

    public static function reportTitle(): string
    {
        return __('Occupancy & vacancy');
    }

    public function table(Table $table): Table
    {
        $count = fn (UnitStatus $status) => fn (Builder $units) => $units->where('status', $status->value);

        // Rent of the active leases with at least one unit in the building.
        $rentUnderLease = DB::table('leases')
            ->where('leases.status', LeaseStatus::ACTIVE->value)
            ->whereExists(fn ($q) => $q->select(DB::raw(1))
                ->from('lease_unit')
                ->join('units', 'units.id', '=', 'lease_unit.unit_id')
                ->whereColumn('lease_unit.lease_id', 'leases.id')
                ->whereColumn('units.property_id', 'properties.id'))
            ->selectRaw('COALESCE(SUM(leases.annual_rent), 0)');

        return $this->report($table)
            ->query(fn () => Property::query()
                ->with('ownerGroup')
                ->withCount([
                    'units',
                    'units as occupied_count' => $count(UnitStatus::OCCUPIED),
                    'units as vacant_count' => $count(UnitStatus::VACANT),
                    'units as maintenance_count' => $count(UnitStatus::UNDER_MAINTENANCE),
                    'units as reserved_count' => $count(UnitStatus::RESERVED),
                ])
                ->selectSub($rentUnderLease, 'rent_under_lease'))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label(__('Building'))
                    ->description(fn (Property $property) => $property->ownerGroup?->name)
                    ->weight('bold')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('units_count')
                    ->label(__('Units'))
                    ->alignCenter()
                    ->sortable()
                    ->summarize(Sum::make()->label('')),
                TextColumn::make('occupied_count')
                    ->label(__('Occupied'))
                    ->alignCenter()
                    ->color('success')
                    ->sortable()
                    ->summarize(Sum::make()->label('')),
                TextColumn::make('vacant_count')
                    ->label(__('Vacant'))
                    ->alignCenter()
                    ->color(fn ($state) => $state > 0 ? 'danger' : null)
                    ->sortable()
                    ->summarize(Sum::make()->label('')),
                TextColumn::make('maintenance_count')
                    ->label(__('Under maintenance'))
                    ->alignCenter()
                    ->sortable()
                    ->summarize(Sum::make()->label('')),
                TextColumn::make('reserved_count')
                    ->label(__('Reserved'))
                    ->alignCenter()
                    ->sortable()
                    ->summarize(Sum::make()->label('')),
                TextColumn::make('occupancy')
                    ->label(__('Occupancy'))
                    ->state(fn (Property $property) => $property->units_count > 0
                        ? round($property->occupied_count / $property->units_count * 100, 1)
                        : null)
                    ->formatStateUsing(fn ($state) => $state.'%')
                    ->badge()
                    ->color(fn ($state) => match (true) {
                        $state >= 90 => 'success',
                        $state >= 70 => 'warning',
                        default => 'danger',
                    })
                    ->placeholder('—'),
                TextColumn::make('rent_under_lease')
                    ->label(__('Annual rent under lease'))
                    ->aed()
                    ->alignEnd()
                    ->sortable()
                    ->summarize(Sum::make()->label('')->aed()),
            ])
            ->filters($this->portfolioFilters(fn (Builder $query, ?int $group, ?int $property) => $query
                ->when($group, fn (Builder $q) => $q->where('owner_group_id', $group))
                ->when($property, fn (Builder $q) => $q->whereKey($property))));
    }
}
