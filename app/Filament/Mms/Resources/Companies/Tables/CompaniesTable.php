<?php

namespace App\Filament\Mms\Resources\Companies\Tables;

use App\Filament\Support\ExpiringDocuments;
use App\Models\Company;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CompaniesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->withCount('ownedAttachments')
                ->withCount(['employees as sponsored_count' => fn (Builder $employees) => $employees->whereNull('date_of_leaving')]))
            ->columns([
                TextColumn::make('name')
                    ->label(__('Company'))
                    ->description(fn (Company $record): ?string => $record->name_ar)
                    ->searchable(['name', 'name_ar'])
                    ->sortable(),
                TextColumn::make('trade_license_no')
                    ->label(__('Licence number'))
                    ->description(fn (Company $record): ?string => $record->license_authority)
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('trade_license_start')
                    ->label(__('Licence start'))
                    ->date()
                    ->sortable()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('trade_license_expiry')
                    ->label(__('Licence expiry'))
                    ->date()
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('establishment_card_no')
                    ->label(__('Establishment card number'))
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('establishment_card_expiry')
                    ->label(__('Establishment card expiry'))
                    ->date()
                    ->sortable()
                    ->placeholder('—'),
                // The alarm: a badge for each document expired (red) or expiring soon (amber).
                ExpiringDocuments::column(Company::EXPIRING),
                // Those on staff whose visas are under its establishment card.
                TextColumn::make('sponsored_count')
                    ->label(__('Sponsored employees'))
                    ->badge()
                    ->color('info')
                    ->icon('heroicon-o-users')
                    ->sortable(),
                TextColumn::make('owned_attachments_count')
                    ->label(__('Documents'))
                    ->badge()
                    ->color('gray')
                    ->icon('heroicon-o-paper-clip')
                    ->toggleable(),
            ])
            ->defaultSort('trade_license_expiry')
            ->filters([
                ExpiringDocuments::filter(Company::EXPIRING),
            ])
            ->recordActions([
                EditAction::make()->iconButton(),
                DeleteAction::make()->iconButton(),
            ])
            ->emptyStateHeading(__('No companies yet'))
            ->emptyStateActions([CreateAction::make()]);
    }
}
