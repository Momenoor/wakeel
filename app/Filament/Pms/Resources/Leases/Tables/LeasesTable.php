<?php

namespace App\Filament\Pms\Resources\Leases\Tables;

use App\Enums\PMS\AttestationStatus;
use App\Enums\PMS\LeaseStatus;
use App\Filament\Pms\Support\PortfolioScope;
use App\Models\Lease;
use Filament\Actions\CreateAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class LeasesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['leaseParties.party', 'units.property']))
            ->columns([
                TextColumn::make('primary_tenant')
                    ->label(__('Primary Tenant'))
                    ->state(fn (Lease $record): string => $record->primaryTenant()?->party?->name ?? '—'),
                TextColumn::make('start_date')
                    ->label(__('Start Date'))
                    ->date()
                    ->sortable(),
                TextColumn::make('end_date')
                    ->label(__('End Date'))
                    ->date()
                    ->sortable(),
                TextColumn::make('total_base_rent')
                    ->label(__('Total Base Rent'))
                    ->numeric(decimalPlaces: 2),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge(),
                TextColumn::make('attestation_status')
                    ->label(__('Attestation'))
                    ->badge(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options(LeaseStatus::class),
                SelectFilter::make('attestation_status')
                    ->label(__('Attestation Status'))
                    ->options(AttestationStatus::class),
                // Same two filters as the PMS dashboard, which links here
                // with them pre-applied (PortfolioScope::leaseTableUrl()).
                SelectFilter::make('owner_group')
                    ->label(__('Owner Group'))
                    ->options(fn (): array => PortfolioScope::ownerGroupOptions())
                    ->searchable()
                    ->query(fn (Builder $query, array $data): Builder => PortfolioScope::leases(
                        $query,
                        filled($data['value'] ?? null) ? (int) $data['value'] : null,
                        null,
                    )),
                SelectFilter::make('property')
                    ->label(__('Building'))
                    ->options(fn (): array => PortfolioScope::propertyOptions())
                    ->searchable()
                    ->query(fn (Builder $query, array $data): Builder => PortfolioScope::leases(
                        $query,
                        null,
                        filled($data['value'] ?? null) ? (int) $data['value'] : null,
                    )),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->emptyStateHeading(__('No leases yet'))
            ->emptyStateActions([
                CreateAction::make(),
            ]);
    }
}
