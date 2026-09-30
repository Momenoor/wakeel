<?php

namespace App\Filament\Mms\Resources\Parties\Tables;

use App\Models\Party;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PartiesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->searchable(),
                TextColumn::make('phone')
                    ->label(__('Phone'))
                    ->badge()
                    ->listWithLineBreaks()
                    ->searchable(),
                TextColumn::make('fax')
                    ->label(__('Fax'))
                    ->searchable(),
                TextColumn::make('email')
                    ->label(__('Email address'))
                    ->badge()
                    ->listWithLineBreaks()
                    ->searchable(),
                TextColumn::make('role')
                    ->label(__('Role'))
                    ->badge()
                    ->listWithLineBreaks()
                    ->getStateUsing(function (Party $record) {
                        $rawRole = $record->getRawOriginal('role');
                        $roles = json_decode($rawRole, true) ?? [];

                        return collect($roles)->map(function ($role) {
                            $label = __($role['role'] ? ucfirst($role['role']) : '');
                            if (isset($role['type'])) {
                                $label .= ' ('.__($role['type'] ? ucfirst($role['type']) : '').')';
                            }
                            if (isset($role['field'])) {
                                $label .= ' - '.__($role['field'] ? ucfirst($role['field']) : '');
                            }

                            return $label;
                        })->toArray();
                    }),
                IconColumn::make('black_list')
                    ->label(__('Black List')),
                TextColumn::make('created_at')
                    ->label(__('Created'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label(__('Updated'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                // Any of the chosen roles.
                SelectFilter::make('role')
                    ->label(__('Role'))
                    ->options(Party::roleOptions())
                    ->multiple()
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['values'] ?? [],
                        fn (Builder $query, array $roles) => $query->where(function (Builder $query) use ($roles): void {
                            foreach ($roles as $role) {
                                $query->orWhere(fn (Builder $q) => $q->withRole($role));
                            }
                        }),
                    )),
                // Experts of any of the chosen types.
                SelectFilter::make('sub_role')
                    ->label(__('Sub Role'))
                    ->options(Party::expertTypeOptions())
                    ->multiple()
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['values'] ?? [],
                        fn (Builder $query, array $types) => $query->where(function (Builder $query) use ($types): void {
                            foreach ($types as $type) {
                                $query->orWhere(fn (Builder $q) => $q->withRole('expert', $type));
                            }
                        }),
                    )),
            ])
            ->recordActions([
                ViewAction::make()->visible(fn ($record) => auth()->user()->can('view', $record)),
                EditAction::make()->visible(fn ($record) => auth()->user()->can('update', $record)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->visible(fn () => auth()->user()->can('deleteAny', Party::class)),
                ]),
            ]);
    }
}
