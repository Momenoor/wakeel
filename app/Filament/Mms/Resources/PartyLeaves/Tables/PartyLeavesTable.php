<?php

namespace App\Filament\Mms\Resources\PartyLeaves\Tables;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PartyLeavesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('party.name')->label(__('Employee'))->searchable()->sortable(),
                TextColumn::make('start_date')->label(__('Start Date'))->date()->sortable(),
                TextColumn::make('end_date')->label(__('End Date'))->date()->sortable(),
                TextColumn::make('leave_type')->label(__('Leave Type'))->badge()->placeholder('—'),
                // What the vacation did to the annual balance: its entry (a request's days are taken off with the
                // request, so they show —).
                TextColumn::make('balanceEntry.days')
                    ->label(__('Balance effect'))
                    ->numeric(decimalPlaces: 1)
                    ->color('danger')
                    ->placeholder('—'),
                TextColumn::make('reason')->label(__('Reason'))->limit(40)->placeholder('—'),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['party', 'balanceEntry']))
            ->defaultSort('start_date', 'desc')
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make()->iconButton(),
                DeleteAction::make()->iconButton(),
            ])
            ->toolbarActions([

            ])
            ->emptyStateHeading(__('No leaves recorded yet'))
            ->emptyStateActions([
                CreateAction::make()->label(__('Add Leave')),
            ]);
    }
}
