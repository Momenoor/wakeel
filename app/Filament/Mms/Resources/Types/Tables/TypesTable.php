<?php

namespace App\Filament\Mms\Resources\Types\Tables;

use App\Filament\Mms\Resources\Types\Schemas\TypeForm;
use App\Models\LetterTemplate;
use App\Models\Type;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class TypesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('incentiveConfig.name')
                    ->label(__('Incentive Config'))
                    ->placeholder(__('No Config'))
                    ->sortable(),
                IconColumn::make('active')
                    ->label(__('Active'))
                    ->boolean()
                    ->sortable(),
                IconColumn::make('allow_current_status_import')
                    ->label(__('Allow Current Status'))
                    ->boolean()
                    ->sortable()
                    ->toggleable(),
                IconColumn::make('exclude_from_incentive_count')
                    ->label(__('Exclude Count'))
                    ->boolean()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('incentive_trigger_type')
                    ->label(__('Incentive Trigger Type'))
                    ->sortable()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'final_report_date' => __('Matter Final Reported'),
                        'fees_registered_date' => __('Fee Registered'),
                        default => $state ? __($state) : '-',
                    })
                    ->toggleable(),
                TextColumn::make('matters_count')
                    ->counts('matters')
                    ->label(__('Matters')),
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
                //
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('assignConfig')
                        ->label(__('Assign Incentive Config'))
                        ->schema([
                            Select::make('incentive_config_id')
                                ->label(__('Incentive Configuration'))
                                ->relationship('incentiveConfig', 'name')
                                ->required(),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $records->each(function ($record) use ($data) {
                                $record->update([
                                    'incentive_config_id' => $data['incentive_config_id'],
                                ]);
                            });
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('assignLetters')
                        ->label(__('Assign letter templates'))
                        ->icon('heroicon-o-envelope')
                        ->schema([
                            Select::make('templates')
                                ->label(__('Letter templates'))
                                ->options(fn () => LetterTemplate::query()->orderBy('name')->pluck('name', 'id'))
                                ->multiple()
                                ->searchable()
                                ->required(),
                            Radio::make('mode')
                                ->label(__('Action'))
                                ->options([
                                    'add' => __('Add to the types\' letters'),
                                    'replace' => __('Replace the types\' letters with these'),
                                    'remove' => __('Remove from the types'),
                                ])
                                ->default('add')
                                ->required(),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $ids = array_map('intval', (array) $data['templates']);

                            $records->each(fn (Type $type) => match ($data['mode'] ?? 'add') {
                                'replace' => $type->letterTemplates()->sync($ids),
                                'remove' => $type->letterTemplates()->detach($ids),
                                default => $type->letterTemplates()->syncWithoutDetaching($ids),
                            });

                            Notification::make()->success()->title(__('Saved'))->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('setCapacities')
                        ->label(__('Set party capacities'))
                        ->icon('heroicon-o-scale')
                        ->schema(TypeForm::capacityFields())
                        ->action(function (Collection $records, array $data): void {
                            $capacities = array_map(fn ($name) => trim((string) $name) ?: null, (array) ($data['party_capacities'] ?? []));
                            $records->each(fn (Type $type) => $type->update(['party_capacities' => $capacities]));

                            Notification::make()->success()->title(__('Saved'))->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
