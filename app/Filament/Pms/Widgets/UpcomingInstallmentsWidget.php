<?php

namespace App\Filament\Pms\Widgets;

use App\Enums\PMS\InstallmentPaymentStatus;
use App\Filament\Pms\Resources\Leases\LeaseResource;
use App\Filament\Pms\Support\PortfolioScope;
use App\Models\Installment;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Unpaid instalments falling due today through the next 7 days, soonest
 * first — narrowed by the dashboard's owner group / building filters.
 */
class UpcomingInstallmentsWidget extends TableWidget
{
    use HasWidgetShield;
    use InteractsWithPageFilters;

    public const DAYS_AHEAD = 7;

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 2;

    public function table(Table $table): Table
    {
        [$ownerGroupId, $propertyId] = PortfolioScope::fromFilters($this->pageFilters);

        return $table
            ->heading(__('Upcoming Instalments (Next :days Days)', ['days' => self::DAYS_AHEAD]))
            ->query(fn (): Builder => PortfolioScope::installments(
                Installment::query()
                    ->with(['lease.leaseParties.party', 'lease.units.property'])
                    ->whereBetween('due_date', [now()->toDateString(), now()->addDays(self::DAYS_AHEAD)->toDateString()])
                    ->where('payment_status', '!=', InstallmentPaymentStatus::PAID),
                $ownerGroupId,
                $propertyId,
            ))
            ->defaultSort('due_date')
            ->columns([
                TextColumn::make('due_date')
                    ->label(__('Due Date'))
                    ->date()
                    ->description(fn (Installment $record): string => $record->due_date->isToday()
                        ? __('Today')
                        : __('In :days days', ['days' => (int) now()->startOfDay()->diffInDays($record->due_date)]))
                    ->sortable(),
                TextColumn::make('tenant')
                    ->label(__('Tenant'))
                    ->state(fn (Installment $record): string => $record->lease?->primaryTenant()?->party?->name ?? '—'),
                TextColumn::make('building')
                    ->label(__('Building'))
                    ->state(fn (Installment $record): string => $record->lease?->units->pluck('property.name')->filter()->unique()->implode(', ') ?: '—'),
                TextColumn::make('units')
                    ->label(__('Units'))
                    ->state(fn (Installment $record): string => $record->lease?->units->pluck('unit_number')->implode(', ') ?: '—'),
                TextColumn::make('balance_due')
                    ->label(__('Amount Due'))
                    ->numeric(decimalPlaces: 2)
                    ->sortable(),
                TextColumn::make('payment_status')
                    ->label(__('Status'))
                    ->badge(),
            ])
            ->recordUrl(fn (Installment $record): ?string => $record->lease
                ? LeaseResource::getUrl('view', ['record' => $record->lease])
                : null)
            ->emptyStateHeading(__('No instalments due in the next :days days', ['days' => self::DAYS_AHEAD]))
            ->paginated([10, 25, 50]);
    }
}
