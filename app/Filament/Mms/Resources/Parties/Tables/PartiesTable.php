<?php

namespace App\Filament\Mms\Resources\Parties\Tables;

use App\Models\ExpertiseArea;
use App\Models\Party;
use App\Services\MMS\PartyMerger;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class PartiesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                // The English name under it, when there is one; found by either.
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->description(fn (Party $record): ?string => $record->name_en)
                    ->searchable(['name', 'name_en']),
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

                        // Each by its own list: "external" is an external entity as
                        // a role, and an external expert only as an expert's type.
                        $roleNames = Party::roleOptions();
                        $typeNames = Party::expertTypeOptions();

                        return collect($roles)->map(function ($role) use ($roleNames, $typeNames) {
                            $label = filled($role['role'] ?? null) ? ($roleNames[$role['role']] ?? __(ucfirst($role['role']))) : '';
                            if (filled($role['type'] ?? null)) {
                                $label .= ' ('.($typeNames[$role['type']] ?? __(ucfirst($role['type']))).')';
                            }
                            if (isset($role['field'])) {
                                $label .= ' - '.ExpertiseArea::labelFor($role['field']);
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
                    // The same person or company entered twice (or more): the ones ticked made one (PartyMerger).
                    BulkAction::make('merge')
                        ->label(__('Merge into one party'))
                        ->icon('heroicon-o-arrows-pointing-in')
                        ->color('warning')
                        ->visible(fn () => auth()->user()->can('deleteAny', Party::class))
                        ->modalHeading(__('Merge the parties ticked into one'))
                        ->modalDescription(__('Everything of the others — their matters and capacities on them, representatives, letters, minutes, emails, contacts, creditors, folders, payroll and the rest — moves to the party kept; its phones and emails take theirs where not there already; then they are deleted. It cannot be undone.'))
                        ->schema([
                            Select::make('into')
                                ->label(__('The party kept'))
                                ->options(fn ($livewire): array => collect($livewire->getSelectedTableRecords())->sortBy('id')->mapWithKeys(fn (Party $party): array => [$party->getKey() => $party->name.' (#'.$party->getKey().')'])->all())
                                ->default(fn ($livewire) => collect($livewire->getSelectedTableRecords())->min('id'))
                                ->required(),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            if ($records->count() < 2) {
                                Notification::make()->warning()->title(__('Tick two parties or more.'))->send();

                                return;
                            }

                            $into = $records->firstWhere('id', (int) $data['into']) ?? $records->sortBy('id')->first();

                            try {
                                $result = app(PartyMerger::class)->merge($into, $records->reject(fn (Party $party): bool => $party->is($into)));
                            } catch (\Throwable $e) {
                                Notification::make()->danger()->title(__('The parties could not be merged'))->body($e->getMessage())->send();

                                return;
                            }

                            Notification::make()->success()
                                ->title(trans_choice(':count party merged into :into.|:count parties merged into :into.', $result['removed'], ['count' => $result['removed'], 'into' => $into->name]))
                                ->body(__('Phones: :phones. Emails: :emails.', ['phones' => implode(', ', Party::values($into->phone)) ?: '—', 'emails' => implode(', ', Party::values($into->email)) ?: '—']))
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    DeleteBulkAction::make()->visible(fn () => auth()->user()->can('deleteAny', Party::class)),
                ]),
            ]);
    }
}
